<?php

/**
* Plugin Name:       Delete Old Orders
* Plugin URI:        https://wordpress.org/plugins/delete-old-orders/
* Description:       Cleaning up old orders in Woocommerce will significantly speed up your website.
* Version:           0.1
* Author:            esiteq
* Author URI:        http://www.esiteq.com/
* License:           GPL-2.0+
* License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
* Text Domain:       wdoo
* Domain Path:       /languages
* Tested up to: 4.9.5
*/

define('WDOO_DIR', realpath(dirname(__file__)));

class Woo_Delete_Old_Orders
{
    //
    function __construct()
    {
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);
        register_activation_hook(__file__, [$this, 'activate']);
        add_action('wp_ajax_woo_delete_old_orders_ajax', [$this, 'woo_delete_old_orders_ajax']);
    }
    //
    function log($msg)
    {
        //file_put_contents(WDOO_DIR. '/log.txt', $msg. "\n", FILE_APPEND);
    }
    //
    function activate()
    {
        global $wpdb;
        $sqls =
        [
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}woo_delete_old_ids` (`post_id` int(12) NOT NULL) ENGINE=MEMORY;",
            "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}woo_delete_old_orders` (`post_id` int(12) NOT NULL) ENGINE=MEMORY;"
        ];
        foreach ($sqls as $sql)
        {
            $wpdb->query($sql);
        }
    }
    //
    function admin_enqueue_scripts()
    {
        wp_enqueue_script('jquery-ui-datepicker');
        wp_register_style('jquery-ui', plugins_url('css/jquery-ui.css', __file__));
        wp_enqueue_style('jquery-ui');
    }
    //
    function admin_menu()
    {
        add_submenu_page('woocommerce', 'Delete Old Orders', 'Delete Old Orders', 'manage_options', 'woo-delete-old-orders', [$this, 'woo_delete_old_orders']);
    }
    //
    function get_ids()
    {
        global $wpdb;
        $rows_meta = $wpdb->get_results("SELECT post_id FROM {$wpdb->prefix}woo_delete_old_ids", ARRAY_A);
        $meta_ids = [];
        if (is_array($rows_meta))
        {
            foreach ($rows_meta as $row)
            {
                $meta_ids[] = $row['post_id'];
            }
        }
        $rows_ids = $wpdb->get_results("SELECT post_id FROM {$wpdb->prefix}woo_delete_old_orders", ARRAY_A);
        $ids = [];
        if (is_array($rows_ids))
        {
            foreach ($rows_ids as $row)
            {
                $ids[] = $row['post_id'];
            }
        }
        $meta_ids = join(',', $meta_ids);
        $ids = join(',', $ids);
        return ['meta_ids'=>$meta_ids, 'ids'=>$ids];
    }
    //
    function woo_delete_old_orders_ajax()
    {
        set_time_limit(3600);
        global $wpdb;
        $table = $_GET['table'];
        $json = [];
        $tmp = $this->get_ids();
        $meta_ids = $tmp['meta_ids'];
        $ids = $tmp['ids'];

        switch ($table)
        {
            case 'woocommerce_order_itemmeta':
                $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id IN (". $meta_ids. ")", 1);
                break;
            case 'woocommerce_order_items':
                $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id IN (". $ids. ")", 1);
                break;
            case 'comments':
                $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}comments WHERE comment_type = 'order_note' AND comment_post_ID IN (". $ids. ")", 1);
                break;
            case 'postmeta':
                $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}postmeta WHERE post_id IN (". $ids. ")", 1);
                break;
            case 'posts':
                $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE ID IN (". $ids. ")", 1);
                break;
        }
        $this->log($sql);
        $sql = str_replace('SELECT COUNT(*)', 'DELETE', $sql);
        $result = 0;
        $result = $wpdb->query($sql);
        $json['count'] = intval($result);
        $json['table'] = $table;
        die(json_encode($json));
    }
    //
    function woo_delete_old_orders()
    {
        global $wpdb;
        $step = intval($_GET['step']);
        if ($step == 0) $step = 1;
?>
<style type="text/css">
.ui-datepicker-trigger {
    vertical-align:middle;
    margin-top:-4px;
    cursor:pointer;
}
</style>
<div class="wrap">
    <div id="icon-options-general" class="icon32"><br /></div>
    <h2>Delete Old Orders</h2>

<?php if ($step == 1): // step 1 ?>

    <div id="step1-error" style="display: none;" class="notice notice-error is-dismissible"><p>Invalid date selected!</p></div>

    <form method="get" id="form-step-1">
        <input type="hidden" name="page" value="woo-delete-old-orders" />
        <input type="hidden" name="step" value="2" />
        <p><label for="till-date">Delete orders before the selected date. Date format is YYYY-MM-DD.</label></p>
        <p><input type="text" id="till-date" name="date" class="datepicker" autocomplete="off" value="<?php echo isset($_GET['date']) ? $_GET['date'] : date('Y-m-d'); ?>" /></p>
        <p><label for="limit-users">Limit orders to delete. It helps if you have many orders, and deletion takes too long.</label></p>
        <p><input type="text" id="limit-users" name="limit" value="<?php echo isset($_GET['limit']) ? intval($_GET['limit']) : 10000; ?>" /></p>
    </form>
    <p><input type="button" name="step2" id="step2" class="button button-primary" value="Next step &raquo;" /></p>

<?php endif; // step 1 ?>

<?php
if ($step == 2):
?>

<?php
        $date = $this->validate_date($_GET['date']) ? $_GET['date'] : '';
        if ($date != '')
        {
            $sql = $wpdb->prepare("TRUNCATE TABLE {$wpdb->prefix}woo_delete_old_orders", 1);
            $wpdb->query($sql);
            $sql = $wpdb->prepare("TRUNCATE TABLE {$wpdb->prefix}woo_delete_old_ids", 1);
            $wpdb->query($sql);
            $limit = intval($_GET['limit']);
            if ($limit == 0)
            {
                $limit = 1000000;
            }
            $sql = $wpdb->prepare("SELECT ID FROM {$wpdb->prefix}posts WHERE post_date < %s AND post_type='shop_order' LIMIT %d", $date, $limit);
            $tmp = $wpdb->get_results($sql, ARRAY_A);
            if (is_array($tmp) && count($tmp) > 0)
            {
                $values = '';
                foreach ($tmp as $t)
                {
                    $values .= '('. $t['ID']. '),';
                }
                $values = substr($values, 0, strlen($values)-1);
                $sql = $wpdb->prepare("INSERT INTO {$wpdb->prefix}woo_delete_old_orders (post_id) VALUES ". $values, 1);
                $wpdb->query($sql);
                $sql = $wpdb->prepare("INSERT INTO {$wpdb->prefix}woo_delete_old_ids (SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id IN (SELECT post_id FROM {$wpdb->prefix}woo_delete_old_orders))", 1);
                $wpdb->query($sql);
                $count = [];
                $tmp = $this->get_ids();
                $meta_ids = $tmp['meta_ids'];
                $ids = $tmp['ids'];
                $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}woo_delete_old_orders", 1);
                $count['orders'] = intval($wpdb->get_var($sql));
                $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id IN (". $meta_ids. ")", 1);
                $count['woocommerce_order_itemmeta'] = intval($wpdb->get_var($sql));
                $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id IN (". $ids. ")", 1);
                $count['woocommerce_order_items'] = intval($wpdb->get_var($sql));
                $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}comments WHERE comment_type = 'order_note' AND comment_post_ID IN (". $ids. ")", 1);
                $count['comments'] = intval($wpdb->get_var($sql));
                $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}postmeta WHERE post_id IN (". $ids. ")", 1);
                $count['postmeta'] = intval($wpdb->get_var($sql));
            }
            //$sql = $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}posts WHERE ID IN (". $ids. ")", 1);
            //$count['posts'] = intval($wpdb->get_var($sql));
?>
<p>Items found for deletion:</p>
<?php
            foreach ($count as $key=>$cnt)
            {
?>
<p><b><?php echo $key; ?></b>: <?php echo $cnt; ?> rows</p>
<?php
            }
?>
<p>Please note that orders will be PERMANENTLY DELETED.</p>
<p>If you still want to do that, click the button below.</p>
<?php if ($count['orders'] > 0): ?>
<p><a href="admin.php?page=woo-delete-old-orders&step=3&date=<?php echo $_GET['date']; ?>&limit=<?php echo $_GET['limit']; ?>" id="step3" class="button button-primary">Proceed with deletion &raquo;</a></p>
<?php endif; ?>
<p><a href="admin.php?page=woo-delete-old-orders&date=<?php echo $_GET['date']; ?>&limit=<?php echo $_GET['limit']; ?>" id="step31" class="button button-primary">&laquo; Go back</a></p>
<?php
        }
endif;
?>
<?php if ($step == 3): ?>
<?php
        $tables = ['woocommerce_order_itemmeta', 'woocommerce_order_items', 'comments', 'postmeta', 'posts'];
        $tables = array_reverse($tables);
?>
<div id="step3-progress">
</div>
<p id="go-back" style="display: none;"><a href="admin.php?page=woo-delete-old-orders&date=<?php echo $_GET['date']; ?>&limit=<?php echo $_GET['limit']; ?>" id="step31" class="button button-primary">&laquo; Go back</a></p>
<script>
var _tables = <?php echo json_encode($tables); ?>;
var table_proc = '';
var total_rows = 0;
jQuery(document).ready(function($)
{
    //
    function displayTotals()
    {
        var html = '<p>Total rows deleted: <b>'+total_rows+'</b></p>';
        $('#step3-progress').append(html);
        $('#go-back').css('display', 'block');
    }
    //
    var ti = setInterval(function()
    {
        if (table_proc == '' && _tables.length > 0)
        {
            table_proc = _tables.pop();
            var html = '<p>Processing table <b>&laquo;'+table_proc+'&raquo;</b>... <img style="vertical-align:middle" id="spinner-'+table_proc+'" width="128" height="15" src="<?php echo plugins_url('images/ajax-loader-3.gif', __file__) ?>" /><span id="result-'+table_proc+'"></span></p>';
            $('#step3-progress').append(html);
            console.log('processing table '+table_proc);
            $.post(ajaxurl + '?action=woo_delete_old_orders_ajax&context=delete&table='+table_proc, { }, function(data)
            {
                $('#spinner-'+data.table).css('display', 'none');
                total_rows += parseInt(data.count);
                var res = '<b>'+data.count+'</b> rows deleted';
                $('#result-'+data.table).html(res);
                console.log(data);
                table_proc = '';
                if (_tables.length == 0)
                {
                    displayTotals();
                }
            }, 'json');
        }
        console.log();
    }, 1000);
});
</script>
<?php endif; ?>

</div>
<script>
function isValidDate(dateString)
{
    var regEx = /^\d{4}-\d{2}-\d{2}$/;
    if (!dateString.match(regEx)) return false;  // Invalid format
    var d = new Date(dateString);
    var dNum = d.getTime();
    if(!dNum && dNum !== 0) return false; // NaN value, Invalid date
    return d.toISOString().slice(0,10) === dateString;
}

jQuery(document).ready(function($)
{
    $('#step2').click(function(e)
    {
        e.preventDefault();
        if (!isValidDate($('#till-date').val()))
        {
            $('#step1-error').css('display', 'block');
        }
        else
        {
            $('#form-step-1').submit();
        }
    });
    $.datepicker.setDefaults(
    {
        showOn: 'both',
        buttonImageOnly: true,
        buttonImage: '<?php echo plugins_url('images/icon-calendar.svg', __file__); ?>',
        buttonText: 'Calendar'
    });
    $('.datepicker').datepicker(
    {
        dateFormat : 'yy-mm-dd',
        changeMonth: true,
        changeYear: true
    });
});
</script>
<?php
    }
    //
    function init()
    {
        // empty function
    }
    //
    function validate_date($date)
    {
        return (bool)preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $date);
    }
}

function wdoo()
{
    global $_wdoo;
    if (!isset($_wdoo))
    {
        $_wdoo = new Woo_Delete_Old_Orders;
    }
    return $_wdoo;
}

wdoo();
?>