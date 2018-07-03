<?php

use YandexCheckout\Model\Payment;

require_once dirname(__FILE__).DIRECTORY_SEPARATOR.'yandex_money'.DIRECTORY_SEPARATOR.'autoload.php';

/**
 * Class ModelExtensionPaymentYandexMoney
 *
 * @property ModelAccountOrder $model_account_order
 * @property ModelCatalogProduct $model_catalog_product
 */
class ModelExtensionPaymentYandexMoney extends Model
{
    const MODULE_VERSION = '1.0.16';

    private $kassaModel;
    private $walletModel;
    private $billingModel;
    private $client;
    private $_prefix;

    /**
     * @return \YandexMoneyModule\Model\KassaModel
     */
    public function getKassaModel()
    {
        if ($this->kassaModel === null) {
            $this->kassaModel = new \YandexMoneyModule\Model\KassaModel($this->config);
        }

        return $this->kassaModel;
    }

    /**
     * @return \YandexMoneyModule\Model\WalletModel
     */
    public function getWalletModel()
    {
        if ($this->walletModel === null) {
            $this->walletModel = new \YandexMoneyModule\Model\WalletModel($this->config);
        }

        return $this->walletModel;
    }

    /**
     * @return \YandexMoneyModule\Model\BillingModel
     */
    public function getBillingModel()
    {
        if ($this->billingModel === null) {
            $this->billingModel = new \YandexMoneyModule\Model\BillingModel($this->config);
        }

        return $this->billingModel;
    }

    /**
     * @return \YandexMoneyModule\Model\AbstractPaymentModel|null
     */
    public function getPaymentModel()
    {
        if ($this->getKassaModel()->isEnabled()) {
            return $this->getKassaModel();
        } elseif ($this->getWalletModel()->isEnabled()) {
            return $this->getWalletModel();
        } elseif ($this->getBillingModel()->isEnabled()) {
            return $this->getBillingModel();
        }

        return null;
    }

    protected function getClient()
    {
        if ($this->client === null) {
            $this->client = new \YandexCheckout\Client\YandexMoneyApi();
            $this->client->setAuth(
                $this->getKassaModel()->getShopId(),
                $this->getKassaModel()->getPassword()
            );
            $this->client->setLogger($this);
        }

        return $this->client;
    }

    public function getMethod($address, $total)
    {
        $result = array();
        $this->load->language($this->getPrefix().'payment/yandex_money');

        $model = $this->getPaymentModel();
        if ($model->getMinPaymentAmount() > 0 && $model->getMinPaymentAmount() > $total) {
            return $result;
        }

        if ($model->getGeoZoneId() > 0) {
            $query = $this->db->query(
                "SELECT * FROM `".DB_PREFIX."zone_to_geo_zone` WHERE `geo_zone_id` = '"
                .(int)$model->getGeoZoneId()."' AND country_id = '".(int)$address['country_id']
                ."' AND (zone_id = '".(int)$address['zone_id']."' OR zone_id = '0')"
            );
            if (empty($query->num_rows)) {
                return $result;
            }
        }
        $result = array(
            'code'       => 'yandex_money',
            'title'      => $model->getDisplayName(),
            'terms'      => '',
            'sort_order' => $this->config->get('yandex_money_sort_order'),
        );

        return $result;
    }

    /**
     * @param int $orderId
     * @param string $paymentMethod
     *
     * @return \YandexCheckout\Model\PaymentInterface
     */
    public function createPayment($orderId, $paymentMethod)
    {
        $this->load->model('checkout/order');
        $orderInfo = $this->model_checkout_order->getOrder($orderId);
        if (empty($orderInfo)) {
            return null;
        }

        $returnUrl = htmlspecialchars_decode(
            $this->url->link($this->getPrefix().'payment/yandex_money/confirm', 'order_id='.$orderId, true)
        );

        $amount = $orderInfo['total'];
        if ($orderInfo['currency_code'] !== 'RUB') {
            $amount = $this->currency->convert($amount, $orderInfo['currency_code'], 'RUB');
        }

        try {
            $builder      = \YandexCheckout\Request\Payments\CreatePaymentRequest::builder();
            $captureValue = $this->getKassaModel()->getCaptureValue($paymentMethod);
            $description  = $this->generatePaymentDescription($orderInfo);
            $builder->setAmount($amount)
                    ->setCurrency('RUB')
                    ->setClientIp($_SERVER['REMOTE_ADDR'])
                    ->setCapture($captureValue)
                    ->setDescription($description)
                    ->setMetadata(array(
                        'order_id'       => $orderId,
                        'cms_name'       => 'ya_api_ycms_opencart',
                        'module_version' => self::MODULE_VERSION,
                    ));

            $confirmation = array(
                'type'      => \YandexCheckout\Model\ConfirmationType::REDIRECT,
                'returnUrl' => $returnUrl,
            );
            if (!$this->getKassaModel()->getEPL()) {
                if ($paymentMethod === \YandexCheckout\Model\PaymentMethodType::ALFABANK) {
                    $data         = array(
                        'type'  => $paymentMethod,
                        'login' => trim($_GET['alphaLogin']),
                    );
                    $confirmation = \YandexCheckout\Model\ConfirmationType::EXTERNAL;
                } elseif ($paymentMethod === \YandexCheckout\Model\PaymentMethodType::QIWI) {
                    $data = array(
                        'type'  => $paymentMethod,
                        'phone' => preg_replace('/[^\d]/', '', $_GET['qiwiPhone']),
                    );
                } else {
                    $data = $paymentMethod;
                }
                $builder->setPaymentMethodData($data);
            } elseif ($paymentMethod === \YandexCheckout\Model\PaymentMethodType::INSTALLMENTS) {
                $builder->setPaymentMethodData($paymentMethod);
            }
            $builder->setConfirmation($confirmation);

            if ($this->getKassaModel()->sendReceipt()) {
                $this->addReceipt($builder, $orderInfo);
            }
            $request = $builder->build();
            if ($this->getKassaModel()->sendReceipt() && $request->getReceipt() !== null) {
                $request->getReceipt()->normalize($request->getAmount());
            }
        } catch (InvalidArgumentException $e) {
            $this->log('error', 'Failed to create create payment request: '.$e->getMessage());

            return null;
        }

        try {
            $payment = $this->getClient()->createPayment($request);
        } catch (Exception $e) {
            $this->log('error', 'Failed to create payment: '.$e->getMessage());
            $payment = null;
        }
        if ($payment !== null) {
            $this->insertPayment($payment, $orderId);
        }

        return $payment;
    }

    public function createOrderPayment($order, $returnUrl = null, $paymentMethod = false)
    {
        if (empty($returnUrl)) {
            $returnUrl = $this->url->link('account/order/info', 'order_id='.$order['order_id'], true);
        }

        $amount = $order['total'];
        if ($order['currency_code'] !== 'RUB') {
            $amount = $this->currency->convert($amount, $order['currency_code'], 'RUB');
        }

        try {
            $builder = \YandexCheckout\Request\Payments\CreatePaymentRequest::builder();
            $builder->setAmount($amount)
                    ->setCurrency('RUB')
                    ->setClientIp($_SERVER['REMOTE_ADDR'])
                    ->setCapture(true);

            $confirmation = array(
                'type'      => \YandexCheckout\Model\ConfirmationType::REDIRECT,
                'returnUrl' => $returnUrl,
            );
            if (!$this->getKassaModel()->getEPL() && !empty($paymentMethod)) {
                if ($paymentMethod === \YandexCheckout\Model\PaymentMethodType::ALFABANK) {
                    $data         = array(
                        'type'  => $paymentMethod,
                        'login' => trim($_GET['alphaLogin']),
                    );
                    $confirmation = \YandexCheckout\Model\ConfirmationType::EXTERNAL;
                } elseif ($paymentMethod === \YandexCheckout\Model\PaymentMethodType::QIWI) {
                    $data = array(
                        'type'  => $paymentMethod,
                        'phone' => preg_replace('/[^\d]/', '', $_GET['qiwiPhone']),
                    );
                } else {
                    $data = $paymentMethod;
                }
                $builder->setPaymentMethodData($data);
            }
            $builder->setConfirmation($confirmation);

            if ($this->getKassaModel()->sendReceipt()) {
                $this->addReceipt($builder, $order);
            }
            $request = $builder->build();
            if ($this->getKassaModel()->sendReceipt() && $request->getReceipt() !== null) {
                $request->getReceipt()->normalize($request->getAmount());
            }
        } catch (InvalidArgumentException $e) {
            $this->log('error', 'Failed to create create payment request: '.$e->getMessage());

            return null;
        }

        try {
            $payment = $this->getClient()->createPayment($request);
        } catch (Exception $e) {
            $this->log('error', 'Failed to create payment: '.$e->getMessage());
            $payment = null;
        }
        if ($payment !== null) {
            $this->insertPayment($payment, $order['order_id']);
        }

        return $payment;
    }

    public function updatePaymentInfo($paymentId)
    {
        $payment = $this->fetchPaymentInfo($paymentId);
        if ($payment !== null) {
            if ($payment->getStatus() !== \YandexCheckout\Model\PaymentStatus::PENDING) {
                $this->updatePaymentInDatabase($payment);
            }
        }

        return $payment;
    }

    /**
     * @param int $orderId
     */
    public function confirmOrder($orderId)
    {
        $this->load->model('checkout/order');
        $url     = $this->url->link($this->getPrefix().'payment/yandex_money/repay', 'order_id='.$orderId, true);
        $comment = '<a href="'.$url.'" class="button">'.$this->language->get('text_repay').'</a>';
        $this->model_checkout_order->addOrderHistory($orderId, 1, $comment);
    }

    /**
     * @param int $orderId
     * @param \YandexCheckout\Model\PaymentInterface $payment
     * @param $statusId
     */
    public function confirmOrderPayment($orderId, $payment, $statusId)
    {
        $this->log('info', 'Confirm captured payment '.$payment->getId().' with status '.$statusId);
        $this->load->model('checkout/order');
        $this->model_checkout_order->addOrderHistory(
            $orderId,
            $statusId,
            'Платёж номер "'.$payment->getId().'" подтверждён'
        );
        $sql = 'UPDATE `'.DB_PREFIX.'order_history` SET `comment` = \'Платёж подтверждён\' WHERE `order_id` = '
               .(int)$orderId.' AND `order_status_id` <= 1';
        $this->db->query($sql);
    }


    /**
     * @param \YandexCheckout\Model\PaymentInterface $payment
     * @param bool $fetchPaymentInfo
     * @param null|int $amount
     *
     * @return \YandexCheckout\Model\PaymentInterface
     */
    public function capturePayment($payment, $fetchPaymentInfo = true, $amount = null)
    {
        if ($fetchPaymentInfo) {
            $tmp = $this->fetchPaymentInfo($payment->getId());
            if ($tmp === null) {
                return $tmp;
            }
            $payment = $tmp;
        }
        if ($payment->getStatus() === \YandexCheckout\Model\PaymentStatus::WAITING_FOR_CAPTURE) {
            try {
                $builder = \YandexCheckout\Request\Payments\Payment\CreateCaptureRequest::builder();
                if (is_null($amount)) {
                    $builder->setAmount($payment->getAmount());
                } else {
                    $builder->setAmount($amount);
                }
                $request = $builder->build();
            } catch (InvalidArgumentException $e) {
                $this->log('error', 'Failed to create capture payment request: '.$e->getMessage());

                return $payment;
            }

            try {
                $response = $this->getClient()->capturePayment($request, $payment->getId());
            } catch (Exception $e) {
                $this->log('error', 'Failed to capture payment: '.$e->getMessage());
                $response = null;
            }
            if ($response !== null) {
                $payment = $response;
                $this->updatePaymentInDatabase($payment);
            }
        }

        return $payment;
    }

    /**
     * @param int $orderId
     *
     * @return string|null
     */
    public function findPaymentIdByOrderId($orderId)
    {
        $sql       = 'SELECT `payment_id` FROM `'.DB_PREFIX.'ya_money_payment` WHERE `order_id` = '.(int)$orderId;
        $resultSet = $this->db->query($sql);
        if (empty($resultSet) || empty($resultSet->num_rows)) {
            return null;
        }

        return $resultSet->row['payment_id'];
    }

    /**
     * @param \YandexCheckout\Model\PaymentInterface $payment
     *
     * @return int
     */
    public function findOrderIdByPayment($payment)
    {
        $sql       = 'SELECT `order_id` FROM `'.DB_PREFIX.'ya_money_payment` WHERE `payment_id` = \''.
                     $this->db->escape($payment->getId()).'\'';
        $resultSet = $this->db->query($sql);
        if (empty($resultSet) || empty($resultSet->num_rows)) {
            return -1;
        }

        return (int)$resultSet->row['order_id'];
    }

    /**
     * @param string $level
     * @param string $message
     * @param array $context
     */
    public function log($level, $message, $context = array())
    {
        if ($this->getKassaModel()->getDebugLog()) {
            $log     = new Log('yandex-money.log');
            $search  = array();
            $replace = array();
            if (!empty($context)) {
                foreach ($context as $key => $value) {
                    $search[]  = '{'.$key.'}';
                    $replace[] = $value;
                }
            }
            $sessionId = $this->session->getId();
            $userId    = 0;
            if (isset($this->session->data['user_id'])) {
                $userId = $this->session->data['user_id'];
            }
            if (empty($search)) {
                $log->write('['.$level.'] ['.$userId.'] ['.$sessionId.'] - '.$message);
            } else {
                $log->write(
                    '['.$level.'] ['.$userId.'] ['.$sessionId.'] - '
                    .str_replace($search, $replace, $message)
                );
            }
        }
    }

    public function checkSign($callbackParams, $password)
    {
        $string = $callbackParams['notification_type'].'&'
                  .$callbackParams['operation_id'].'&'
                  .$callbackParams['amount'].'&'
                  .$callbackParams['currency'].'&'
                  .$callbackParams['datetime'].'&'
                  .$callbackParams['sender'].'&'
                  .$callbackParams['codepro'].'&'
                  .$password.'&'
                  .$callbackParams['label'];
        if (sha1($string) !== $callbackParams['sha1_hash']) {
            header('HTTP/1.0 401 Unauthorized');

            return false;
        }

        return true;
    }

    /**
     * @param \YandexCheckout\Request\Payments\CreatePaymentRequestBuilder $builder
     * @param array $orderInfo
     */
    private function addReceipt($builder, $orderInfo)
    {
        $this->load->model('account/order');
        $this->load->model('catalog/product');

        if (isset($orderInfo['email'])) {
            $builder->setReceiptEmail($orderInfo['email']);
        } elseif (isset($orderInfo['phone'])) {
            $builder->setReceiptPhone($orderInfo['phone']);
        }
        $taxRates       = $this->config->get('yandex_money_kassa_tax_rates');
        $defaultTaxRate = $this->config->get('yandex_money_kassa_tax_rate_default');

        $orderProducts = $this->model_account_order->getOrderProducts($orderInfo['order_id']);
        foreach ($orderProducts as $prod) {
            $productInfo = $this->model_catalog_product->getProduct($prod['product_id']);
            $price       = $this->currency->format($prod['price'], 'RUB', '', false);
            if (isset($productInfo['tax_class_id'])) {
                $taxId = $productInfo['tax_class_id'];
                if (isset($taxRates[$taxId])) {
                    $builder->addReceiptItem($prod['name'], $price, $prod['quantity'], $taxRates[$taxId]);
                } else {
                    $builder->addReceiptItem($prod['name'], $price, $prod['quantity'], $defaultTaxRate);
                }
            } else {
                $builder->addReceiptItem($prod['name'], $price, $prod['quantity'], $defaultTaxRate);
            }
        }

        $order_totals = $this->model_account_order->getOrderTotals($orderInfo['order_id']);
        foreach ($order_totals as $total) {
            if (isset($total['code']) && $total['code'] === 'shipping') {
                $price = $this->currency->format($total['value'], 'RUB', '', false);
                if (isset($total['tax_class_id'])) {
                    $taxId = $total['tax_class_id'];
                    $builder->addReceiptShipping($total['title'], $price, $taxRates[$taxId]);
                } else {
                    $builder->addReceiptShipping($total['title'], $price, $defaultTaxRate);
                }
            }
        }
    }

    /**
     * @param string $paymentId
     *
     * @return \YandexCheckout\Model\PaymentInterface|null
     */
    public function fetchPaymentInfo($paymentId)
    {
        try {
            $payment = $this->getClient()->getPaymentInfo($paymentId);
        } catch (Exception $e) {
            $this->log('error', 'Failed to fetch payment info: '.$e->getMessage());
            $payment = null;
        }

        return $payment;
    }

    public function getMetricsJavaScript($id)
    {
        if (!$this->config->get('yandex_money_metrika_active')
            || !$this->config->get('yandex_money_metrika_code')
        ) {
             return '';
        }

        $this->load->model('checkout/order');
        $order              = $this->model_checkout_order->getOrder($id);
        $product_array      = $this->getOrderProducts($id);

        $products = array();
        foreach ($product_array as $k => $product) {
            $products[$k]['id']       = $product['product_id'];
            $products[$k]['name']     = $product['name'];
            $products[$k]['quantity'] = (int)$product['quantity'];
            $products[$k]['price']    = (float)$product['price'];
        }

        $ecommerce = array(
            'currencyCode' => $order['currency_code'],
            'purchase'     => array(
                'actionField' => array(
                    'id'      => $order['order_id'],
                    'revenue' => $order['total'],
                ),
                'products'    => $products,
            ),
        );

        $data = '<script type="text/javascript">
            $(window).on("load", function() {
                window.dataLayer = window.dataLayer || [];
                dataLayer.push({ecommerce: '.json_encode($ecommerce).'});
            });
            </script>';

        return $data;
    }

    public function getOrderProducts($order_id)
    {
        $query = $this->db->query('SELECT * FROM `'.DB_PREFIX.'order_product` WHERE order_id = '.(int)$order_id);

        return $query->rows;
    }

    /**
     * @param \YandexCheckout\Model\PaymentInterface $payment
     * @param int $orderId
     */
    private function insertPayment($payment, $orderId)
    {
        $paymentMethodId = '';
        if ($payment->getPaymentMethod() !== null) {
            $paymentMethodId = $payment->getPaymentMethod()->getId();
        }
        $sql = 'INSERT INTO `'.DB_PREFIX.'ya_money_payment` (`order_id`, `payment_id`, `status`, `amount`, '
               .'`currency`, `payment_method_id`, `paid`, `created_at`) VALUES ('
               .(int)$orderId.','
               ."'".$this->db->escape($payment->getId())."',"
               ."'".$this->db->escape($payment->getStatus())."',"
               ."'".$this->db->escape($payment->getAmount()->getValue())."',"
               ."'".$this->db->escape($payment->getAmount()->getCurrency())."',"
               ."'".$this->db->escape($paymentMethodId)."',"
               ."'".($payment->getPaid() ? 'Y' : 'N')."',"
               ."'".$this->db->escape($payment->getCreatedAt()->format('Y-m-d H:i:s'))."'"
               .') ON DUPLICATE KEY UPDATE '
               .'`payment_id` = VALUES(`payment_id`),'
               .'`status` = VALUES(`status`),'
               .'`amount` = VALUES(`amount`),'
               .'`currency` = VALUES(`currency`),'
               .'`payment_method_id` = VALUES(`payment_method_id`),'
               .'`paid` = VALUES(`paid`),'
               .'`created_at` = VALUES(`created_at`)';
        $this->db->query($sql);
    }

    /**
     * @param \YandexCheckout\Model\PaymentInterface $payment
     */
    private function updatePaymentInDatabase($payment)
    {
        $updates = array(
            "`status` = '".$this->db->escape($payment->getStatus())."'",
            "`paid` = '".($payment->getPaid() ? 'Y' : 'N')."'",
        );
        if ($payment->getCapturedAt() !== null) {
            $updates[] = "`captured_at` = '".$this->db->escape($payment->getCapturedAt()->format('Y-m-d H:i:s'))."'";
        }
        $sql = 'UPDATE `'.DB_PREFIX.'ya_money_payment` SET '.implode(',', $updates)
               .' WHERE `payment_id` = \''.$this->db->escape($payment->getId())."'";
        $this->db->query($sql);
    }

    private function getPrefix()
    {
        if ($this->_prefix === null) {
            $this->_prefix = '';
            if (version_compare(VERSION, '2.3.0') >= 0) {
                $this->_prefix = 'extension/';
            }
        }

        return $this->_prefix;
    }

    /**
     * @param $orderInfo
     *
     * @return string
     */
    private function generatePaymentDescription($orderInfo)
    {
        $this->load->language($this->getPrefix().'payment/yandex_money');

        $descriptionTemplate = $this->getKassaModel()->getPaymentDescription();
        if (!$descriptionTemplate) {
            $descriptionTemplate = $this->language->get('kassa_default_payment_description');
        }
        $replace = array();
        foreach ($orderInfo as $key => $value) {
            if (is_scalar($value)) {
                $replace['%'.$key.'%'] = $value;
            }
        }
        $description = strtr($descriptionTemplate, $replace);

        $description = (string)mb_substr($description, 0, Payment::MAX_LENGTH_DESCRIPTION);

        return $description;
    }
}

class ModelPaymentYandexMoney extends ModelExtensionPaymentYandexMoney
{
}