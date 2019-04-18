<?php

use YandexCheckout\Model\Confirmation\ConfirmationRedirect;
use YandexCheckout\Model\PaymentMethodType;
use YandexCheckout\Model\PaymentStatus;
use YandexMoney\InstallmentsApi;

define("YANDEXMONEY_WS_HTTP_CATALOG", '/');
require_once(DIR_FS_CATALOG.'ext/modules/payment/yandex_money/yandex_money.php');
$GLOBALS['YandexMoneyObject'] = new YandexMoneyObj();

require_once(dirname(__FILE__).'/yandex_money/autoload.php');

class Yandex_Money
{
    const MODE_NONE = 0;
    const MODE_KASSA = 1;
    const MODE_MONEY = 2;
    const MODE_BILLING = 3;

    const MODULE_VERSION = '1.0.7';
    const INSTALLMENTS_MIN_AMOUNT = 3000;

    public $code;
    public $title;
    public $description;
    public $enabled;
    public $org;
    private $epl;
    private $mode;

    public function __construct()
    {
        $this->signature = 'YandexMoney|YandexMoney|'.self::MODULE_VERSION.'|'.self::MODULE_VERSION;

        $this->code         = 'yandex_money';
        $this->title        = MODULE_PAYMENT_YANDEX_MONEY_TEXT_TITLE;
        $this->public_title = MODULE_PAYMENT_YANDEX_MONEY_TEXT_PUBLIC_TITLE;

        $this->description = MODULE_PAYMENT_YANDEX_MONEY_TEXT_DESCRIPTION;

        $this->description = str_replace('{notification_url}', HTTPS_SERVER.DIR_WS_HTTPS_CATALOG.'callback.php',
            $this->description);

        $this->sort_order = MODULE_PAYMENT_YANDEXMONEY_SORT_ORDER;
        $this->enabled    = true;

        $this->mode = self::MODE_NONE;
        if (MODULE_PAYMENT_YANDEXMONEY_MODE == MODULE_PAYMENT_YANDEXMONEY_MODE1) {
            $this->mode = self::MODE_KASSA;
        } elseif (MODULE_PAYMENT_YANDEXMONEY_MODE == MODULE_PAYMENT_YANDEXMONEY_MODE2) {
            $this->mode = self::MODE_MONEY;
        } elseif (MODULE_PAYMENT_YANDEXMONEY_MODE == MODULE_PAYMENT_YANDEXMONEY_MODE3) {
            $this->mode = self::MODE_BILLING;
        }
        $this->epl = (MODULE_PAYMENT_YANDEX_MONEY_PAYMENT_MODE == MODULE_PAYMENT_YANDEX_MONEY_FALSE);

        $GLOBALS['YandexMoneyObject']->mode      = $this->mode;
        $GLOBALS['YandexMoneyObject']->test_mode = (MODULE_PAYMENT_YANDEXMONEY_TEST == MODULE_PAYMENT_YANDEX_MONEY_TRUE);
        $GLOBALS['YandexMoneyObject']->epl       = $this->epl;

        if ($this->mode === self::MODE_MONEY) {
            preg_match_all("'\<\!\-\-jur_start\-\-\>(.*?)\<\!\-\-jur_end\-\-\>'ims", $this->description, $res);
            $this->description = str_replace($res[0][0], "", $this->description);
            $this->description = str_replace($res[0][1], "", $this->description);
        } elseif ($this->mode === self::MODE_KASSA) {
            preg_match_all("'\<\!\-\-ind_start\-\-\>(.*?)\<\!\-\-ind_end\-\-\>'ims", $this->description, $res);
            $this->description = str_replace($res[0][0], "", $this->description);
            $this->description = str_replace($res[0][1], "", $this->description);
        }

        $this->applyVersionInfo();
    }

    private function applyVersionInfo()
    {
        $version     = $this->getUpdater()->getVersionInfo();
        $versionText = '<h4>О модуле:</h4><ul><li>Установленная версия модуля — '.self::MODULE_VERSION.'</li>'
                       .'<li>Последняя версия модуля — '.$version['newVersion'].'</li>'
                       .'<li>Последняя проверка наличия новых версий — '.$version['newVersionInfo']['date'].'</li></ul>';
        if ($version['new_version_available']) {
            $versionText .= '<h4>История изменений:</h4><p>'.$version['changelog'].'</p>'
                            .'<a href="javascript://" class="ui-button ui-widget ui-state-default ui-corner-all ui-button-text-icon-primary ui-priority-primary" id="update-module"><span class="ui-button-icon-primary ui-icon ui-icon-document"></span><span class="ui-button-text">Обновить</span></a>';
        } else {
            $versionText .= '<p>Установлена последняя версия модуля.</p>';
        }

        $backups = $this->getUpdater()->getBackupList();
        if (!empty($backups)) {
            $versionText .= '<p><a id="backup-list" class="ui-button ui-widget ui-state-default ui-corner-all ui-button-text-icon-primary ui-priority-secondary" href="javascript://"><span class="ui-button-icon-primary ui-icon ui-icon-document"></span><span class="ui-button-text">Резервные копии ('
                            .count($backups).')</span></a></p><div id="backup-list-window" style="display:none;"><table><thead><tr>'
                            .'<th>Версия</th><th>Имя файла</th><th>Дата создания</th><th></th><th></th></tr></thead><tbody><tr>'
                            .'<td></td><td></td><td></td><td></td><td></td></tr></tbody></table></div>';
        }

        $js = <<<JS
<style>
#backup-list-window table {
    width: 100%;
}
#backup-list-window th, #backup-list-window td {
    padding: 5px 10px;
}
</style>
<script type="text/javascript">
jQuery(document).ready(function () {
    
    jQuery('#backup-list-window').delegate('a.restore-backup', 'click', restoreBackupHandler);
    jQuery('#backup-list-window').delegate('a.remove-backup', 'click', removeBackupHandler);
    
    jQuery('#update-module').click(updateModuleHandler);
    
    jQuery('#backup-list').click(function () {
        jQuery.ajax({
            url: 'ext/modules/payment/yandex_money/ajax.php',
            method: 'GET',
            data: {
                action: 'backup_list'
            },
            dataType: 'json',
            success: function (result) {
                if (result.success) {
                    var tpl = '';
                    for (var i = 0; i < result.list.length; ++i) {
                        tpl += '<tr class="backup-row" data-name="' + result.list[i].name + '" data-id="' + result.list[i].version + '">'
                            + '<td>' + result.list[i].version + '</td>'
                            + '<td>' + result.list[i].name + '</td>'
                            + '<td>' + result.list[i].date + '</td>'
                            + '<td><a href="javascript://" class="restore-backup ui-button ui-widget ui-state-default ui-corner-all ui-button-text-icon-primary ui-priority-secondary"><span class="ui-button-icon-primary ui-icon ui-icon-document"></span><span class="ui-button-text">Восстановить</span></a></td>'
                            + '<td><a href="javascript://" class="remove-backup ui-button ui-widget ui-state-default ui-corner-all ui-button-text-icon-primary ui-priority-secondary"><span class="ui-button-icon-primary ui-icon ui-icon-document"></span><span class="ui-button-text">Удалить</span></a></td></tr>';
                    }
                    jQuery('#backup-list-window table tbody').html(tpl);
                    jQuery('#backup-list-window').dialog({
                        width: 700
                    });
                }
            }
        });
    });
    
    function restoreBackupHandler() {
        var row = jQuery(this).parents('tr.backup-row')[0];
        if (window.confirm('Вы действительно хотите восстановить резервную копию "' + row.dataset.id + '" из файла "' + row.dataset.name + '"?')) {
            jQuery.ajax({
                method: 'POST',
                url: 'ext/modules/payment/yandex_money/ajax.php?action=restore_backup',
                data: {
                    file_name: row.dataset.name
                },
                dataType: 'json',
                success: function (res) {
                    alert(res.message);
                    if (res.success) {
                        document.location = document.location;
                    }
                }
            });
        }
    }
    
    function removeBackupHandler() {
        var row = jQuery(this).parents('tr.backup-row')[0];
        if (window.confirm('Вы действительно хотите удалить резервную копию "' + row.dataset.name + '" для версии "' + row.dataset.id + '"?')) {
            jQuery.ajax({
                method: 'POST',
                url: 'ext/modules/payment/yandex_money/ajax.php?action=remove_backup',
                data: {
                    file_name: row.dataset.name
                },
                dataType: 'json',
                success: function (res) {
                    alert(res.message);
                    if (res.success) {
                        row.remove();
                    }
                }
            });
        }
    }
    
    function updateModuleHandler() {
        if (window.confirm('Вы действительно хотите обновить модуль до последней версии?')) {
            jQuery.ajax({
                method: 'GET',
                url: 'ext/modules/payment/yandex_money/ajax.php?action=update',
                data: {
                    action: 'update'
                },
                dataType: 'json',
                success: function (res) {
                    alert(res.message);
                    if (res.success) {
                        document.location = document.location;
                    }
                }
            });
        }
    }
});
</script>
JS;

        $this->description .= $versionText.$js;
    }

    public function javascript_validation()
    {

        return false;
    }

    public function selection()
    {
        global $cart_YandexMoney_ID, $order;

        if (tep_session_is_registered('cart_YandexMoney_ID')) {
            $order_id = substr($cart_YandexMoney_ID, strpos($cart_YandexMoney_ID, '-') + 1);

            $check_query = tep_db_query('select orders_id from '.TABLE_ORDERS_STATUS_HISTORY.' where orders_id = "'.(int)$order_id.'" limit 1');

            if (tep_db_num_rows($check_query) < 1) {
                tep_db_query('delete from '.TABLE_ORDERS.' where orders_id = "'.(int)$order_id.'"');
                tep_db_query('delete from '.TABLE_ORDERS_TOTAL.' where orders_id = "'.(int)$order_id.'"');
                tep_db_query('delete from '.TABLE_ORDERS_STATUS_HISTORY.' where orders_id = "'.(int)$order_id.'"');
                tep_db_query('delete from '.TABLE_ORDERS_PRODUCTS.' where orders_id = "'.(int)$order_id.'"');
                tep_db_query('delete from '.TABLE_ORDERS_PRODUCTS_ATTRIBUTES.' where orders_id = "'.(int)$order_id.'"');
                tep_db_query('delete from '.TABLE_ORDERS_PRODUCTS_DOWNLOAD.' where orders_id = "'.(int)$order_id.'"');

                tep_session_unregister('cart_YandexMoney_ID');
            }
        }

        if ($this->mode === self::MODE_NONE) {
            return false;
        }

        if ($this->mode !== self::MODE_BILLING) {
            if ($this->epl && $this->mode === self::MODE_KASSA) {
                $result = array(
                    'id'     => $this->code,
                    'module' => MODULE_PAYMENT_YANDEX_MONEY_DISPLAY_TITLE,
                    'fields' => array(),
                );
            } else {
                $additional_fields = array();
                $payment_types     = array();
                foreach (PaymentMethodType::getEnabledValues() as $value) {
                    if ($value !== PaymentMethodType::B2B_SBERBANK) {
                        $const = 'MODULE_PAYMENT_YANDEX_MONEY_PAYMENT_METHOD_'.strtoupper($value);
                        if (defined($const) && constant($const) == MODULE_PAYMENT_YANDEX_MONEY_TRUE) {
                            $const .= '_TEXT';
                            if ($value === PaymentMethodType::INSTALLMENTS) {
                                $shopId = $this->getKassa()->getShopId();
                                $amount = $order->info['total'];
                                if (self::INSTALLMENTS_MIN_AMOUNT > $amount) {
                                    continue;
                                }

                                $monthlyInstallment = InstallmentsApi::creditPreSchedule($shopId, $amount);
                                if (!isset($monthlyInstallment['amount'])) {
                                    $errorMessage = InstallmentsApi::getLastError() ?: 'Unknown error. Could not get installment amount';
                                    $this->log('error', $errorMessage);
                                } else {
                                    $text             = defined($const) ? constant($const) : $const;
                                    $installmentLabel = sprintf($text,
                                        $monthlyInstallment['amount']);
                                    $payment_types[]  = array('id' => $value, 'text' => $installmentLabel);

                                }
                            } else {
                                $payment_types[] = array(
                                    'id'   => $value,
                                    'text' => defined($const) ? constant($const) : $const,
                                );
                            }

                            if ($value === PaymentMethodType::QIWI) {
                                $additional_fields[] = array(
                                    'title' => '',
                                    'field' => '<label for="ya-qiwi-phone">'.MODULE_PAYMENT_YANDEX_MONEY_QIWI_PHONE_LABEL.'</label>'
                                               .tep_draw_input_field('ym_qiwi_phone', '', 'id="ya-qiwi-phone"')
                                               .'<div id="ya-qiwi-phone-error" style="display: none;">'.MODULE_PAYMENT_YANDEX_MONEY_QIWI_PHONE_DESCRIPTION.'</div>',
                                );
                            } elseif ($value === PaymentMethodType::ALFABANK) {
                                $additional_fields[] = array(
                                    'title' => '',
                                    'field' => '<label for="ya-alfa-login">'.MODULE_PAYMENT_YANDEX_MONEY_ALFA_LOGIN_LABEL.'</label>'
                                               .tep_draw_input_field('ym_alfa_login', '', 'id="ya-alfa-login"')
                                               .'<div id="ya-alfa-login-error" style="display: none;">'.MODULE_PAYMENT_YANDEX_MONEY_ALFA_LOGIN_DESCRIPTION.'</div>',
                                );
                            }
                        }
                    }
                }

                if (count($payment_types) == 0) {
                    $result = false;
                } else {
                    $result = array(
                        'id'     => $this->code,
                        'module' => MODULE_PAYMENT_YANDEX_MONEY_DISPLAY_TITLE,
                        'fields' => array(
                            array('title' => '', 'field' => MODULE_PAYMENT_YANDEX_MONEY_TEXT_PUBLIC_DESCRIPTION),
                            array('title' => '', 'field' => tep_draw_pull_down_menu('ym_payment_type', $payment_types)),
                        ),
                    );
                    if (!empty($additional_fields)) {
                        $additional_fields[] = array(
                            'title' => '',
                            'field' => '<script>
jQuery(document).ready(function () {
    var form = document.forms.checkout_payment;

    var qiwiBlock = jQuery("#ya-qiwi-phone").parent().parent();
    var alfaBlock = jQuery("#ya-alfa-login").parent().parent();
    
    qiwiBlock.css("display", "none");
    alfaBlock.css("display", "none");
    
    jQuery(form.ym_payment_type).change(function () {
        qiwiBlock.css("display", "none");
        alfaBlock.css("display", "none");
        if (jQuery(this).val() === "qiwi") {
            qiwiBlock.css("display", "table-row");
        } else if (jQuery(this).val() === "alfabank") {
            alfaBlock.css("display", "table-row");
        }
    });
    for (var i = 0; i < form.length; ++i) {
        if (form[i].className == "btn btn-success") {
            form[i].addEventListener("click", function (e) {
                if (form.ym_payment_type.value == "qiwi") {
                    e.preventDefault();
                    e.stopPropagation();
                    var field = document.getElementById("ya-qiwi-phone");
                    var error = document.getElementById("ya-qiwi-phone-error");
                    var phone = field.value.replace(/[^\d]+/, "");
                    if (phone.length > 4) {
                        error.style.display = \'none\';
                        form.submit();
                    } else {
                        error.style.display = \'block\';
                    }
                } else if (form.ym_payment_type.value == "alfabank") {
                    e.preventDefault();
                    e.stopPropagation();
                    var field = document.getElementById("ya-alfa-login");
                    var error = document.getElementById("ya-alfa-login-error");
                    var login = field.value.trim();
                    if (login.length > 1) {
                        error.style.display = \'none\';
                        form.submit();
                    } else {
                        error.style.display = \'block\';
                    }
                }
            }, false);
        }
    }
});

                            </script>',
                        );
                        $result['fields']    = array_merge($result['fields'], $additional_fields);
                    }
                }
            }
        } else {
            $fio         = array();
            $customer_id = isset($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : -1;
            if ($customer_id > 0) {
                $customer = tep_db_query(
                    "select customers_firstname, customers_lastname from ".TABLE_CUSTOMERS
                    ." where customers_id = '".$customer_id."'"
                );
                $row      = tep_db_fetch_array($customer);
                if (!empty($row)) {
                    if (!empty($row['customers_lastname'])) {
                        $fio[] = $row['customers_lastname'];
                    }
                    if (!empty($row['customers_firstname'])) {
                        $fio[] = $row['customers_firstname'];
                    }
                }
            }
            $result = array(
                'id'     => $this->code,
                'module' => $this->public_title,
                'fields' => array(
                    array(
                        'title' => '',
                        'field' =>
                            '<label for="ya-billing-fio">'.MODULE_PAYMENT_YANDEXMONEY_BILLING_FIO_LABEL.'</label>'
                            .tep_draw_input_field('ym_billing_fio', implode(' ', $fio), 'id="ya-billing-fio"')
                            .'<div id="ya-billing-fio-error" style="display: none;">Укажите фамилию, имя и отчество плательщика</div>',
                    ),
                    array(
                        'title' => '',
                        'field' => '<script>
jQuery(document).ready(function () {
    var form = document.forms.checkout_payment;

    for (var i = 0; i < form.length; ++i) {
        if (form[i].className == "btn btn-success") {
            form[i].addEventListener("click", function (e) {
                e.preventDefault();
                e.stopPropagation();
                var field = document.getElementById("ya-billing-fio");
                var error = document.getElementById("ya-billing-fio-error");
                var parts = field.value.trim().split(/\s+/);
                if (parts.length == 3) {
                    error.style.display = \'none\';
                    field.value = parts.join(\' \');
                    form.submit();
                } else {
                    error.style.display = \'block\';
                }
            }, false);
        }
    }
});
                            </script>',
                    ),
                ),
            );
        }

        return $result;
    }

    public function pre_confirmation_check()
    {
        global $cartID, $cart;

        if (empty($cart->cartID)) {
            $cartID = $cart->cartID = $cart->generate_cart_id();
        }
        if (!tep_session_is_registered('cartID')) {
            tep_session_register('cartID');
        }
    }

    public function confirmation()
    {
        if ($this->mode === self::MODE_BILLING) {
            if (!isset($_POST['ym_billing_fio'])) {
                return false;
            }
            tep_session_register('ym_billing_fio');
            $_SESSION['ym_billing_fio'] = trim($_POST['ym_billing_fio']);

            return array(
                'title'  => MODULE_PAYMENT_YANDEXMONEY_BILLING_TITLE,
                'fields' => array(
                    array(
                        'title' => MODULE_PAYMENT_YANDEXMONEY_BILLING_FIO_LABEL.':',
                        'field' => trim($_POST['ym_billing_fio']),
                    ),
                ),
            );
        }
    }

    public function process_button()
    {
        if (!tep_session_is_registered('ym_payment_type')) {
            tep_session_register('ym_payment_type');
        }
        if (isset($_POST['ym_payment_type'])) {
            $_SESSION['ym_payment_type'] = $_POST['ym_payment_type'];
            if ($_POST['ym_payment_type'] === 'qiwi') {
                $_SESSION['ym_qiwi_phone'] = preg_replace('/[^\d]+/', '', $_POST['ym_qiwi_phone']);
            }
            if ($_POST['ym_payment_type'] === 'alfabank') {
                $_SESSION['ym_alfa_login'] = trim($_POST['ym_alfa_login']);
            }
        } else {
            unset($_SESSION['ym_payment_type'], $_SESSION['ym_qiwi_phone'], $_SESSION['ym_alfa_login']);
        }
        if (isset($_POST['ym_billing_fio'])) {
            $_SESSION['ym_billing_fio'] = trim($_POST['ym_billing_fio']);
        }

        return '';
    }

    public function update_status()
    {
        if ($this->mode == self::MODE_KASSA) {
            $this->log('debug', 'Check for return url');
            if (isset($_GET['payment_confirmation']) && $_GET['payment_confirmation'] == '1') {
                $orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : -1;
                $this->log('debug', 'Check payment for order#'.$orderId);
                if ($orderId <= 0) {
                    return;
                }
                $paymentId = $this->getKassa()->fetchPaymentIdByOrderId($orderId);
                $this->log('debug', 'Payment id is '.$paymentId);
                if (empty($paymentId)) {
                    return;
                }
                $payment = $this->getKassa()->fetchPayment($paymentId);
                if (empty($payment)) {
                    $this->log('warning', 'Payment with id '.$paymentId.' not exits');

                    return;
                }
                if ($payment->getPaid()) {
                    if ($payment->getStatus() === PaymentStatus::WAITING_FOR_CAPTURE) {
                        $capturedPayment = $this->getKassa()->capturePayment($payment, false);
                        if ($capturedPayment !== null) {
                            $payment = $capturedPayment;
                        }
                    }
                    if ($payment->getStatus() === PaymentStatus::SUCCEEDED) {
                        $sqlData = array('orders_status' => (int)MODULE_PAYMENT_YANDEX_MONEY_ORDER_STATUS);
                        tep_db_perform(TABLE_ORDERS, $sqlData, 'update', 'orders_id='.$orderId);
                    }
                    $this->clearCart();
                    $redirectUrl = tep_href_link(FILENAME_CHECKOUT_SUCCESS);
                    tep_redirect($redirectUrl);
                } else {
                    $this->log('info', 'Payment with id '.$paymentId.' not paid');
                }
            }
        }
    }

    public function after_process()
    {
        global $order, $insert_id;

        /** @var YandexMoneyObj $YandexMoneyObject */
        $YandexMoneyObject = &$GLOBALS['YandexMoneyObject'];

        $ym_payment_type = $_SESSION['ym_payment_type'];
        $order_id        = (int)$insert_id;
        if ($this->mode == self::MODE_KASSA) {
            $redirectUrl             = str_replace(
                '&amp;',
                '&',
                tep_href_link(
                    FILENAME_CHECKOUT_CONFIRMATION,
                    'payment_confirmation=1&order_id='.$order_id,
                    'SSL',
                    false,
                    false
                )
            );
            $order->info['order_id'] = $order_id;
            $payment                 = $this->getKassa()->createPayment($order, $ym_payment_type, $redirectUrl);

            if ($payment !== null) {
                $confirmation = $payment->getConfirmation();
                if ($confirmation instanceof ConfirmationRedirect) {
                    $redirectUrl = $confirmation->getConfirmationUrl();
                }
            } else {
                $redirectUrl = tep_href_link(FILENAME_CHECKOUT_PAYMENT);
            }
            tep_redirect($redirectUrl);
        } elseif ($this->mode == self::MODE_MONEY) {
            $process_button_string =
                tep_draw_hidden_field('receiver', MODULE_PAYMENT_YANDEXMONEY_ACCOUNT).
                tep_draw_hidden_field('formcomment', STORE_NAME).
                tep_draw_hidden_field('short-dest', STORE_NAME).
                tep_draw_hidden_field('writable-targets', $YandexMoneyObject->writable_targets).
                tep_draw_hidden_field('comment-needed', $YandexMoneyObject->comment_needed).
                tep_draw_hidden_field('label', $order_id).
                tep_draw_hidden_field('quickpay-form', $YandexMoneyObject->quickpay_form).
                tep_draw_hidden_field('paymentType', $ym_payment_type).
                tep_draw_hidden_field('targets', MODULE_PAYMENT_YANDEXMONEY_ORDER_NAME.' '.$order_id).
                tep_draw_hidden_field('comment', $order->info['comments']).
                tep_draw_hidden_field('need-fio', $YandexMoneyObject->need_fio).
                tep_draw_hidden_field('need-email', $YandexMoneyObject->need_email).
                tep_draw_hidden_field('need-phone', $YandexMoneyObject->need_phone).
                tep_draw_hidden_field('need-address', $YandexMoneyObject->need_address);
        } elseif ($this->mode == self::MODE_BILLING) {

            $sqlData = array('orders_status' => (int)MODULE_PAYMENT_YANDEXMONEY_BILLING_ORDER_STATUS_ID);
            tep_db_perform(TABLE_ORDERS, $sqlData, 'update', 'orders_id='.$order_id);

            $sqlData = array(
                'orders_id'         => $order_id,
                'orders_status_id'  => (int)MODULE_PAYMENT_YANDEXMONEY_BILLING_ORDER_STATUS_ID,
                'date_added'        => 'now()',
                'customer_notified' => '0',
                'comments'          => '',
            );
            tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sqlData);

            $fio                   = $_SESSION['ym_billing_fio'];
            $process_button_string =
                tep_draw_hidden_field('formId', MODULE_PAYMENT_YANDEXMONEY_BILLING_ID).
                tep_draw_hidden_field('fio', $fio).
                tep_draw_hidden_field('narrative',
                    $YandexMoneyObject->parsePlaceholders(MODULE_PAYMENT_YANDEXMONEY_BILLING_PURPOSE, $order_id,
                        $order)).
                tep_draw_hidden_field('quickPayVersion', '2');
        } else {
            $process_button_string = '';
        }
        if (!empty($process_button_string)) {
            $process_button_string .=
                tep_draw_hidden_field('sum', number_format($order->info['total'], 2, '.', '')).
                tep_draw_hidden_field('cms_name', 'oscommerce');
            echo '<form action="'.$YandexMoneyObject->getFormUrl().'" method="post" id="ym-form-submit">'
                 .$process_button_string.'</form>'
                 .'<script> document.getElementById("ym-form-submit").submit(); </script>';
            $this->clearCart();
            exit();
        }
    }

    public function before_process()
    {
    }

    private function clearCart()
    {
        /** @var shoppingCart $cart */
        global $cart;
        $cart->reset(true);

        // unregister session variables used during checkout
        tep_session_unregister('sendto');
        tep_session_unregister('billto');
        tep_session_unregister('shipping');
        tep_session_unregister('payment');
        tep_session_unregister('comments');

        tep_session_unregister('cart_YandexMoney_ID');
    }

    public function check()
    {
        if (!isset($this->_check)) {
            $check_query  = tep_db_query("SELECT `configuration_value` FROM ".TABLE_CONFIGURATION
                                         ." WHERE `configuration_key` = 'MODULE_PAYMENT_YANDEX_MONEY_SHOP_ID'");
            $this->_check = tep_db_num_rows($check_query);
        }

        return $this->_check;
    }

    /**
     * Возвращает список настроек модуля, которые можно редактировать
     * @return string[] Массив имен настроек модуля
     */
    public function keys()
    {
        if ($this->mode === self::MODE_KASSA) {
            $array = array(
                'MODULE_PAYMENT_YANDEX_MONEY_SHOP_ID',
                'MODULE_PAYMENT_YANDEX_MONEY_SHOP_PASSWORD',
                'MODULE_PAYMENT_YANDEX_MONEY_PAYMENT_MODE',
                'MODULE_PAYMENT_YANDEX_MONEY_PAYMENT_DESCRIPTION',
            );
            foreach (PaymentMethodType::getEnabledValues() as $value) {
                if ($value !== PaymentMethodType::B2B_SBERBANK) {
                    $array[] = 'MODULE_PAYMENT_YANDEX_MONEY_PAYMENT_METHOD_'.strtoupper($value);
                }
            }
            $array[] = 'MODULE_PAYMENT_YANDEX_MONEY_SORT_ORDER';
            $array[] = 'MODULE_PAYMENT_YANDEX_MONEY_ORDER_STATUS';
            $array[] = 'MODULE_PAYMENT_YANDEX_MONEY_SEND_RECEIPT';

            $sql     = "SELECT r.tax_rates_id FROM ".TABLE_TAX_CLASS." tc, ".TABLE_TAX_RATES." r LEFT JOIN "
                       .TABLE_GEO_ZONES." z on r.tax_zone_id = z.geo_zone_id WHERE r.tax_class_id = tc.tax_class_id";
            $dataSet = tep_db_query($sql);
            while ($taxRate = tep_db_fetch_array($dataSet)) {
                $array[] = 'MODULE_PAYMENT_YANDEX_MONEY_TAXES_'.$taxRate['tax_rates_id'];
            }
            $array[] = 'MODULE_PAYMENT_YANDEX_MONEY_DISPLAY_TITLE';
            $array[] = 'MODULE_PAYMENT_YANDEX_MONEY_ENABLE_LOG';

            return $array;
        } elseif ($this->mode === self::MODE_MONEY) {
            return array(
                'MODULE_PAYMENT_YANDEXMONEY_STATUS',
                'MODULE_PAYMENT_YANDEXMONEY_TEST',
                'MODULE_PAYMENT_YANDEXMONEY_PASSWORD',
                'MODULE_PAYMENT_YANDEXMONEY_ACCEPT_YANDEXMONEY',
                'MODULE_PAYMENT_YANDEXMONEY_ACCEPT_CARDS',
                'MODULE_PAYMENT_YANDEXMONEY_ACCOUNT',
                'MODULE_PAYMENT_YANDEXMONEY_SORT_ORDER',
                'MODULE_PAYMENT_YANDEXMONEY_ORDER_STATUS_ID',
            );
        } elseif ($this->mode === self::MODE_BILLING) {
            return array(
                'MODULE_PAYMENT_YANDEXMONEY_BILLING_STATUS',
                'MODULE_PAYMENT_YANDEXMONEY_BILLING_ID',
                'MODULE_PAYMENT_YANDEXMONEY_BILLING_PURPOSE',
                'MODULE_PAYMENT_YANDEXMONEY_BILLING_ORDER_STATUS_ID',
            );
        }
    }

    public function log($level, $message)
    {
        if (!defined('MODULE_PAYMENT_YANDEX_MONEY_ENABLE_LOG') || MODULE_PAYMENT_YANDEX_MONEY_ENABLE_LOG == MODULE_PAYMENT_YANDEX_MONEY_FALSE) {
            return;
        }

        $dirName = dirname(__FILE__).'/yandex_money/logs';
        if (!file_exists($dirName)) {
            mkdir($dirName);
        }
        $fileName = $dirName.'/log.log';
        $fd       = @fopen($fileName, 'a');
        if (!$fd) {
            return;
        }
        flock($fd, LOCK_EX);

        $userId  = isset($_SESSION['customer_id']) ? (int)$_SESSION['customer_id'] : -1;
        $message = date(DATE_ATOM).' - ['.$level.'] ['.$userId.'] ['.session_id().'] - '.$message;
        fwrite($fd, $message."\r\n");

        flock($fd, LOCK_UN);
        fclose($fd);
    }

    public function install()
    {
        global $cfgModules, $language;
        $module_language_directory = $cfgModules->get('payment', 'language_directory');
        include_once($module_language_directory.$language."/modules/payment/yandex_money.php");

        if (MODULE_PAYMENT_YANDEXMONEY_MODE == MODULE_PAYMENT_YANDEXMONEY_MODE1) {
            $installer = new \YandexMoney\Installer();
            $installer->install();

            return;
        }

        tep_db_query("insert into ".TABLE_CONFIGURATION." (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values (
            '".MODULE_PAYMENT_YANDEXMONEY_TEST_LANG."', 
            'MODULE_PAYMENT_YANDEXMONEY_TEST', 
            '".MODULE_PAYMENT_YANDEX_MONEY_TRUE."', 
            '', 
            '6', '0', 'tep_cfg_select_option(array(\'".MODULE_PAYMENT_YANDEX_MONEY_TRUE."\', \'".MODULE_PAYMENT_YANDEX_MONEY_FALSE."\'), ', now())"
        );

        tep_db_query("insert into ".TABLE_CONFIGURATION." (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values (
            '".MODULE_PAYMENT_YANDEXMONEY_STATUS_LNG."', 
            'MODULE_PAYMENT_YANDEXMONEY_STATUS', 
            '".MODULE_PAYMENT_YANDEX_MONEY_TRUE."', 
            '', 
            '6', '0', 'tep_cfg_select_option(array(\'".MODULE_PAYMENT_YANDEX_MONEY_TRUE."\', \'".MODULE_PAYMENT_YANDEX_MONEY_FALSE."\'), ', now())"
        );
        tep_db_query("insert into ".TABLE_CONFIGURATION." (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values (
            '".MODULE_PAYMENT_YANDEXMONEY_ACCEPT_YANDEXMONEY_LNG."', 
            'MODULE_PAYMENT_YANDEXMONEY_ACCEPT_YANDEXMONEY', 
           '".MODULE_PAYMENT_YANDEX_MONEY_TRUE."', 
            '', 
            '6', '0', 'tep_cfg_select_option(array(\'".MODULE_PAYMENT_YANDEX_MONEY_TRUE."\', \'".MODULE_PAYMENT_YANDEX_MONEY_FALSE."\'),', now())"
        );
        tep_db_query("insert into ".TABLE_CONFIGURATION." (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values (
            '".MODULE_PAYMENT_YANDEXMONEY_ACCEPT_CARDS_LNG."', 
            'MODULE_PAYMENT_YANDEXMONEY_ACCEPT_CARDS', 
            '".MODULE_PAYMENT_YANDEX_MONEY_TRUE."', 
            '', 
            '6', '0', 'tep_cfg_select_option(array(\'".MODULE_PAYMENT_YANDEX_MONEY_TRUE."\', \'".MODULE_PAYMENT_YANDEX_MONEY_FALSE."\'),', now())"
        );

        tep_db_query("insert into ".TABLE_CONFIGURATION." (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values (
        '".MODULE_PAYMENT_YANDEXMONEY_ACCOUNT_LNG."',
        'MODULE_PAYMENT_YANDEXMONEY_ACCOUNT', 
        '', 
         '".MODULE_PAYMENT_YANDEXMONEY_ONLY_IND_LNG."', 
        '6', '0', now())");

        tep_db_query("insert into ".TABLE_CONFIGURATION." (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values (
            '".MODULE_PAYMENT_YANDEXMONEY_PASSWORD_LNG."', 
            'MODULE_PAYMENT_YANDEXMONEY_PASSWORD', 
            '', 
            '', 
            '6', '0', now())");


        tep_db_query("insert into ".TABLE_CONFIGURATION." (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values (
            '".MODULE_PAYMENT_YANDEXMONEY_SORT_LNG."', 
            'MODULE_PAYMENT_YANDEXMONEY_SORT_ORDER', 
            '0', 
            '".MODULE_PAYMENT_YANDEXMONEY_SORT2_LNG."', 
            '6', '0', now())");
        tep_db_query("insert into ".TABLE_CONFIGURATION." (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values (
            '".MODULE_PAYMENT_YANDEXMONEY_ORDER_STATUS_LNG."', 
            'MODULE_PAYMENT_YANDEXMONEY_ORDER_STATUS_ID', 
            '', 
            '', 
            '6', '0', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");


        /** Птатёжка */

        tep_db_query("insert into ".TABLE_CONFIGURATION." (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values (
            '".MODULE_PAYMENT_YANDEXMONEY_BILLING_STATUS_LNG."', 
            'MODULE_PAYMENT_YANDEXMONEY_BILLING_STATUS', 
            '".MODULE_PAYMENT_YANDEX_MONEY_TRUE."', 
            '',
            '6', '0', 'tep_cfg_select_option(array(\'".MODULE_PAYMENT_YANDEX_MONEY_TRUE."\', \'".MODULE_PAYMENT_YANDEX_MONEY_FALSE."\'),', now())"
        );

        tep_db_query("insert into ".TABLE_CONFIGURATION." (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values (
            '".MODULE_PAYMENT_YANDEXMONEY_BILLING_ID_LNG."', 
            'MODULE_PAYMENT_YANDEXMONEY_BILLING_ID', 
            '', 
            '',
            '6', '0', now())"
        );

        tep_db_query("insert into ".TABLE_CONFIGURATION." (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values (
            '".MODULE_PAYMENT_YANDEXMONEY_BILLING_PURPOSE_LNG."', 
            'MODULE_PAYMENT_YANDEXMONEY_BILLING_PURPOSE', 
            '".MODULE_PAYMENT_YANDEXMONEY_BILLING_PURPOSE_DEF_LNG."', 
            '".MODULE_PAYMENT_YANDEXMONEY_BILLING_PURPOSE_DESC_LNG."',
            '6', '0', now())"
        );

        tep_db_query("insert into ".TABLE_CONFIGURATION." (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values (
            '".MODULE_PAYMENT_YANDEXMONEY_BILLING_ORDER_STATUS_ID_LNG."', 
            'MODULE_PAYMENT_YANDEXMONEY_BILLING_ORDER_STATUS_ID', 
            '2', 
            '".MODULE_PAYMENT_YANDEXMONEY_BILLING_ORDER_STATUS_ID_DESC_LNG."', 
            '6', '0', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())"
        );
    }

    function remove()
    {
        if (MODULE_PAYMENT_YANDEXMONEY_MODE == MODULE_PAYMENT_YANDEXMONEY_MODE1) {
            $installer = new \YandexMoney\Installer();
            $installer->uninstall();
        }
        tep_db_query(
            'DELETE FROM '.TABLE_CONFIGURATION.' WHERE `configuration_key` IN (\''.implode("', '", $this->keys())."')"
        );
    }

    function tep_href_link(
        $page = '',
        $parameters = '',
        $connection = 'NONSSL',
        $add_session_id = true,
        $search_engine_safe = true
    ) {
        global $request_type, $session_started, $SID;

        if (!tep_not_null($page)) {
            exit;
        }

        if ($connection == 'NONSSL') {
            $link = HTTP_SERVER.YANDEXMONEY_WS_HTTP_CATALOG;
        } elseif ($connection == 'SSL') {
            if (ENABLE_SSL === true) {
                $link = HTTPS_SERVER.YANDEXMONEY_WS_HTTP_CATALOG;
            } else {
                $link = HTTP_SERVER.YANDEXMONEY_WS_HTTP_CATALOG;
            }
        } else {
            exit;
        }

        if (tep_not_null($parameters)) {
            $link      .= $page.'?'.tep_output_string($parameters);
            $separator = '&';
        } else {
            $link      .= $page;
            $separator = '?';
        }

        while ((substr($link, -1) == '&') || (substr($link, -1) == '?')) {
            $link = substr($link, 0, -1);
        }

        // Add the session ID when moving from different HTTP and HTTPS servers, or when SID is defined
        if (($add_session_id == true) && ($session_started == true) && (SESSION_FORCE_COOKIE_USE == 'False')) {
            if (tep_not_null($SID)) {
                $_sid = $SID;
            } elseif ((($request_type == 'NONSSL') && ($connection == 'SSL') && (ENABLE_SSL == true)) || (($request_type == 'SSL') && ($connection == 'NONSSL'))) {
                if (HTTP_COOKIE_DOMAIN != HTTPS_COOKIE_DOMAIN) {
                    $_sid = tep_session_name().'='.tep_session_id();
                }
            }
        }

        if ((SEARCH_ENGINE_FRIENDLY_URLS == 'true') && ($search_engine_safe == true)) {
            while (strstr($link, '&&')) {
                $link = str_replace('&&', '&', $link);
            }

            $link = str_replace('?', '/', $link);
            $link = str_replace('&', '/', $link);
            $link = str_replace('=', '/', $link);

            $separator = '?';
        }

        if (isset($_sid)) {
            $link .= $separator.$_sid;
        }

        return $link;
    }

    /**
     * @var \YandexMoney\PaymentMethod\KassaPaymentMethod
     */
    private $kassaPaymentMethod;

    /**
     * @return \YandexMoney\PaymentMethod\KassaPaymentMethod
     */
    public function getKassa()
    {
        if ($this->kassaPaymentMethod === null) {
            $this->kassaPaymentMethod = new \YandexMoney\PaymentMethod\KassaPaymentMethod($this);
        }

        return $this->kassaPaymentMethod;
    }

    private $updaterModule;

    public function getUpdater()
    {
        if ($this->updaterModule === null) {
            $this->updaterModule = new \YandexMoney\Updater($this);
        }

        return $this->updaterModule;
    }
}

function get_options_taxes($id = 1, $default)
{
    return tep_draw_pull_down_menu('configuration[MODULE_PAYMENT_YANDEX_MONEY_TAXES_'.$id.']', getTaxRates(), $default);
}

function getTaxRates()
{
    return array(
        array('id' => 1, 'text' => MODULE_PAYMENT_YANDEXMONEY_WITHOUT_VAT_LNG),
        array('id' => 2, 'text' => MODULE_PAYMENT_YANDEXMONEY_VAT_0_LNG),
        array('id' => 3, 'text' => MODULE_PAYMENT_YANDEXMONEY_VAT_10_LNG),
        array('id' => 4, 'text' => MODULE_PAYMENT_YANDEXMONEY_VAT_20_LNG),
        array('id' => 5, 'text' => MODULE_PAYMENT_YANDEXMONEY_VAT_10_110_LNG),
        array('id' => 6, 'text' => MODULE_PAYMENT_YANDEXMONEY_VAT_20_120_LNG),
    );
}

function get_setted_taxes($id)
{
    $taxes = array();
    foreach (getTaxRates() as $tax) {
        $taxes[$tax['id']] = $tax['text'];
    }
    if (isset($taxes[$id])) {
        return $taxes[$id];
    }
}