<?php
namespace OCM\Lib;
use OCM as OcmCore; 
final class Xlevel {
    private $registry;
    private $ocm;
    private $mtype;
    private $setting;
    private $_cached = array();
    private $current_user_discount = array();
    public function __construct($registry) {
        $this->registry = $registry;
        $this->ocm = $this->registry->has('ocm_front') ? $this->registry->get('ocm_front') : new OcmCore\Front($this->registry);
        $this->mtype = 'module';
        $this->setting = $this->getSetting();
    }
    public function __get($name) {
       return $this->registry->get($name);
    }
    private function getSettingByLang($setting) {
        $language_id = (int)$this->config->get('config_language_id');
        $lang_fields = array('price_prefix', 'email_title', 'total_title', 'menu_title', 'email_content', 'admin_subject', 'admin_content');
        foreach ($lang_fields as $field) {
            $setting[$field] = isset($setting[$field][$language_id]) ? $this->ocm->html_decode($setting[$field][$language_id]) : '';
        }
        if (isset($setting['levels']) && is_array($setting['levels'])) {
            foreach ($setting['levels'] as &$level) {
               if ($level['level_id'] == 0) continue;
               $level['name'] = isset($level['name'][$language_id]) ? $level['name'][$language_id] : 'Untitled Level';
            }
        }
        return $setting;
    }
    public function getSetting() {
        if (!$this->ocm->getConfig('xlevel_status', $this->mtype)) {
            return false;
        }
        if (!defined('HTTP_CATALOG')) {
            $this->load->language('extension/xlevel/module/xlevel');
        }
        $this->load->model('tool/image');
        $setting = $this->cache->get('ocm.xlevel');
        if (!$setting) {
            $language_id = (int)$this->config->get('config_language_id');
            $levels = array();
            $levels_desc = array();
            $sort_order = array();
            $rows =  $this->db->query("SELECT * FROM " . DB_PREFIX . "xlevel ORDER BY total ASC")->rows;
            foreach ($rows as $i => $row) {
               $name = json_decode($row['name'], true);
               if (is_file(DIR_IMAGE . $row['image'])) {
                  $image = $this->model_tool_image->resize($row['image'], 70, 70);
               } else {
                  $image = $this->model_tool_image->resize('no_image.png', 70, 70);
               }

               $_discount = array();
               $discount = trim(trim($row['discount']), '-');
               if (substr($discount, -1) == '%') {
                    $discount = rtrim($discount,'%');
                    $_discount['percent'] = true;
                    $_discount['value'] = (float)$discount / 100;
                    $discount_format = $discount. '%';
               } else {
                    $_discount['percent'] = false;
                    $_discount['value'] = (float)$discount;
                    $discount_format = $this->currency->format($discount, $this->session->data['currency']);
               }
               $levels[$row['level_id']] = array(
                 'level_id' => $row['level_id'],
                 'name' => $name,
                 'badge' => $image,
                 'total' => (float)$row['total'], 
                 'minimum' => (float)$row['minimum'],
                 'discount' => $_discount,
                 'discount_format' => $discount_format
               );
               $levels_desc[] = array(
                 'level_id' => $row['level_id'],
                 'total' => (float)$row['total']
               );
               $sort_order[] = (float)$row['total']; 
            }
            array_multisort($sort_order, SORT_DESC, $levels_desc);

            $fields = array('general','exclusion', 'price_prefix', 'total_title', 'menu_title', 'email_title', 'email_content', 'admin_subject', 'admin_content');
            $config_row = $this->db->query("SELECT * FROM `" . DB_PREFIX . "xlevel_setting`")->row;
            $config = array();
            foreach ($fields as $field) {
               $config[$field] = $config_row ? json_decode($config_row[$field], true) : array();
            }
            $exclusion = $config['exclusion'];
            $exclusion['special'] = isset($exclusion['special']) && $exclusion['special'] ? true : false;
            $exclusion['discount'] = isset($exclusion['discount']) && $exclusion['discount']  ? true : false;
            $exclusion['product'] = isset($exclusion['product']) && $exclusion['product']  ? $exclusion['product'] : false;
            $exclusion['category'] = isset($exclusion['category']) && $exclusion['category']  ? $exclusion['category'] : false;
            $exclusion['manufacturer'] = isset($exclusion['manufacturer']) && $exclusion['manufacturer']  ? $exclusion['manufacturer'] : false;
            
            $general = $config['general'];
            $general['notify_customer'] = isset($general['notify_customer']) && $general['notify_customer'] ? true : false;
            $general['notify_admin'] = isset($general['notify_admin']) && $general['notify_admin'] ? true : false;
            $general['downgrade'] = isset($general['downgrade']) && $general['downgrade'] ? true : false;
            $general['negative_total'] = isset($general['negative_total']) && $general['negative_total'] ? true : false;
            $general['discounted_price'] = isset($general['discounted_price']) && $general['discounted_price'] ? true : false;
            $general['option_price'] = isset($general['option_price']) && $general['option_price'] ? true : false;
            $general['store'] = isset($general['store']) && is_array($general['store']) ? $general['store'] : array();
            $general['customer_group'] = isset($general['customer_group']) && is_array($general['customer_group']) ? $general['customer_group'] : array();
            $general['total_code'] = isset($general['total_code']) && is_array($general['total_code']) ? $general['total_code'] : array('sub_total');
            $general['rounding'] = !empty($general['rounding']) ? $general['rounding'] : 'none';
            $general['page_id'] = !empty($general['page_id']) ? $general['page_id'] : '';
            $general['menu_js'] = !empty($general['menu_js']) ? $this->ocm->html_decode($general['menu_js']) : "";
            $general['menu_ui'] = !empty($general['menu_ui']) ? $this->ocm->html_decode($general['menu_ui']) : "";
            $general['no_level_img'] = !empty($general['no_level_img']) ? $general['no_level_img'] : '';
            $general['complete_status'] = !empty($general['complete_status']) ? $general['complete_status'] : $this->config->get('config_complete_status');
            $general['admin_email'] = !empty($general['admin_email']) ? $general['admin_email'] : array();

            $setting = array();
            $setting['status'] = (bool)$this->ocm->getConfig('xlevel_status', $this->mtype);
            $setting['general'] = $general;
            $setting['exclusion'] = $exclusion;
            $setting['levels'] = $levels;
            $setting['levels_desc'] = $levels_desc;
            $setting['price_prefix'] = $config['price_prefix'];
            $setting['total_title'] = $config['total_title'];
            $setting['menu_title'] = $config['menu_title'];
            $setting['email_title'] = $config['email_title'];
            $setting['email_content'] = $config['email_content'];
            $setting['admin_subject'] = $config['admin_subject'];
            $setting['admin_content'] = $config['admin_content'];
            $setting['order_period'] = (int)$general['order_period'];
            $setting['downgrade_period'] = $setting['order_period'] ? $setting['order_period'] : (int)$general['downgrade_period'];
            $setting['order_period_sql'] = (int)$general['order_period'] ? ' AND date_added >  DATE_SUB(NOW(), INTERVAL '. (int)$general['order_period'] .' MONTH)' : '';
            $setting['downgrade_period_sql'] = ' AND date_added >  DATE_SUB(NOW(), INTERVAL '. ( $setting['order_period'] ? (int)$setting['order_period'] : (int)$setting['downgrade_period'] ) .' MONTH)';
            $setting['downgrade_validity_sql'] =' AND DATE_ADD(date_added, INTERVAL ' . ( $setting['order_period'] ? (int)$setting['order_period'] : (int)$setting['downgrade_period'] ) . ' MONTH) < NOW()';
            $setting['level_validity_sql'] =' AND DATE_ADD(date_added, INTERVAL ' . $setting['order_period'] . ' MONTH) < NOW()';
            $this->cache->set('ocm.xlevel', $setting);
        }
        if (isset($setting['levels'])) {
            $no_image = $setting['general']['no_level_img'] ? $setting['general']['no_level_img'] : 'no_image.png';
            $setting['levels'][0] = array(
                'level_id' => 0,
                'name'     => $this->language->get('text_no_level'),
                'badge'    => $this->model_tool_image->resize($no_image, 70, 70),
                'total'    => 0,
                'minimum'  => 0,
                'discount' => false,
                'discount_format' => '0%'
            );
        }
        return $this->getSettingByLang($setting);
    }
    public function getPricePrefix() {
        if (isset($this->setting['general']['discounted_price']) && !$this->setting['general']['discounted_price']) {
            return '';
        }
        if (!$this->customer->getId() || !$this->ocm->getConfig('xlevel_status', $this->mtype)) {
            return '';
        }
        return $this->setting['price_prefix'];
    }
    public function getCurrentUserDiscount() {
        return $this->current_user_discount;
    }
    private function getTargetLevel($target, $is_next = false) {
        $level_id = 0;
        $next_level_id = 0;
        foreach($this->setting['levels_desc'] as $level) {
            if ($target >= $level['total']) {
              $level_id = $level['level_id'];
              break;
            }
            $next_level_id = $level['level_id'];
        }
        return $is_next ? $next_level_id : $level_id;
    }
    public function applyCartPrice($data) {
       return $this->getLevelPrice($data['product'], $data['option_price'], 'cart');
    }
    public function getDiscountedProduct($product) {
       if (isset($this->setting['general']['discounted_price']) && $this->setting['general']['discounted_price']) {
            return $this->getLevelPrice($product);
        }
        return false;
    }
    public function getLevelPrice($product, $option_price = 0, $source = 'product') {
        if (!$this->customer->getId() || !$this->ocm->getConfig('xlevel_status', $this->mtype)) {
            return false;
        }
        $cache_key = $product['product_id'] . '_' . $product['price'] .'_' . $option_price  . '_' . $source;
        if (!empty($this->_cached[$cache_key])) {
            return $this->_cached[$cache_key];
        }
        $xlevel = $this->getLevelCustomer($this->customer->getId());
        if (!$xlevel || !$xlevel['level_id'] || !isset($this->setting['levels'][$xlevel['level_id']])) {
            return false;
        }
        $exclusion = $this->setting['exclusion'];
        if ($product['special'] && $exclusion['special']) {
           return false;
        }
        if ($product['discount'] && $exclusion['discount']) {
           return false;
        } 
        $customer_group_id = $this->customer->getGroupId();
        $store_id = $this->config->get('config_store_id');
        if (!in_array($customer_group_id, $this->setting['general']['customer_group'])) {
            return false;
        }
        if (!in_array($store_id, $this->setting['general']['store'])) {
            return false;
        }
        if ($exclusion['category']) {
            $excluded_categories = array();
            $categories = $this->db->query("SELECT category_id FROM " . DB_PREFIX . "product_to_category WHERE product_id = '" . (int)$product['product_id'] . "'")->rows;
            foreach ($categories as $category) {
                $excluded_categories[] = $category['category_id'];
            }
            if ((boolean)array_intersect($exclusion['category'], $excluded_categories)) {
                return false;
            }
        }
        if ($exclusion['product'] && in_array($product['product_id'], $exclusion['product'])) {
            return false;
        }
        if ($exclusion['manufacturer'] && in_array($product['manufacturer_id'], $exclusion['manufacturer'])) {
            return false;
        }
        // don't consider ocm_price as it applies disocunt to the latest price
        $original_price = $product['price']; 
        if ((float)$product['special']) {
            $original_price = $product['special'];
        }
        $xlevel_discount = $this->setting['levels'][$xlevel['level_id']]['discount'];
        $this->current_user_discount = $xlevel_discount;
        $discounted_amount = $xlevel_discount['percent'] ? ($xlevel_discount['value'] * $original_price) : $xlevel_discount['value'];
        $discounted_price = $original_price - $discounted_amount;
        if ($discounted_price >= 0 && ($discounted_amount || $this->setting['general']['option_price'])) {
            $return = array();
            $return['amount'] = $discounted_amount;
            $return['price'] = $discounted_price;
            $return['type'] = 'special';
            $this->request->post['_xlevel'][$product['product_id']] = $xlevel_discount;
            $_option_price = $this->getOptionPrice($option_price, (int)$product['product_id']);
            if ($_option_price !== false) {
                $return['option_price'] = $_option_price['price'];
                $return['option_amount'] = $_option_price['amount'];
            }
            if ($this->setting['general']['final'] == 'total') {
                $return['on_total'] = true;
            }
            $return['no_return'] = true;
            // rounding prices if it requires
            if ($this->setting['general']['rounding'] !== 'none') {
                $return['price'] = $this->setting['general']['rounding']($return['price']);
                if (!empty($return['option_price'])) {
                    $return['option_price'] = $this->setting['general']['rounding']($return['option_price']);
                }
            }
            $this->_cached[$cache_key] = $return;
            return $return;
        }
        return  false;
    }
    public function getOptionPrice($price, $product_id, $prefix = '+') {
        if (!$this->setting['general']['option_price'] || !$price || !isset($this->request->post['_xlevel'][$product_id])) {
           return false;
        }
        $return = false;
        $xlevel_discount = $this->request->post['_xlevel'][$product_id];
        $discounted_amount = $xlevel_discount['percent'] ? ($xlevel_discount['value'] * $price) : $xlevel_discount['value'];
        if ($discounted_amount) {
            $return = array();
            if ($price > 0 && $prefix == '-') { // option price could be negative
                $return['price'] =  ($price + $discounted_amount);
            } else {
                $return['price'] = $price - $discounted_amount;
            }
            $return['amount'] = $discounted_amount;
            $return['no_return'] = true;
        }
        return $return;
    }
    public function getLevelCustomer($customer_id, $downgrade = false) {
        $sql = "SELECT * FROM " . DB_PREFIX . "xlevel_customer WHERE customer_id = '" . (int)$customer_id . "'";
        if ($downgrade) {
            $sql .= $this->setting['downgrade_validity_sql'];
        }
        return $this->db->query($sql)->row;
    }
    public function applyOrderReward($order_id, $order_status_id) {
        $customer = $this->db->query("SELECT customer_id FROM `" . DB_PREFIX . "order` WHERE order_id = '" . (int)$order_id . "'")->row;
        $customer_id = $customer ? $customer['customer_id'] : 0;
        $complete_status = $this->setting['general']['complete_status'];
        if (in_array($order_status_id, $complete_status) && $customer_id) {
            $total = (float)$this->db->query("SELECT SUM(`value`) AS total FROM " . DB_PREFIX . "order_total WHERE order_id = '".(int)$order_id."' AND code IN ('" . implode("','", $this->setting['general']['total_code']) . "')")->row['total'];
            if ($this->setting['general']['negative_total']) {
                $total_deduct = $this->db->query("SELECT SUM(`value`) AS total FROM " . DB_PREFIX . "order_total WHERE order_id = '".(int)$order_id."' AND value < 0")->row['total'];
                $total += (float)$total_deduct;
            }
            if ($total < 0) {
                $total = 0;
            }
            if ($total >= 0) {
                $data = array(
                    'customer_id' => $customer_id,
                    'order_id' => $order_id,
                    'type' => 'order',
                    'description' => 'Order Rewarded',
                    'amount' => $total
                );
                $this->addReward($data);
            }
        } else if ($customer_id) {
            $this->db->query("DELETE FROM `" . DB_PREFIX . "xlevel_history` WHERE order_id = '" . (int)$order_id . "'");
            $this->setLevel($customer_id);
        }
    }
    public function addReward($data) {
        $update_sql = false;
        if ($data['order_id']) {
            $row = $this->db->query("SELECT id FROM `" . DB_PREFIX . "xlevel_history` WHERE order_id = '" . (int)$data['order_id'] . "'")->row;
            if ($row) {
                $update_sql = " WHERE id = '".(int)$row['id']."'";
            }
        }
        if ($update_sql) {
            $this->db->query("UPDATE " . DB_PREFIX . "xlevel_history SET amount = '" . (float)$data['amount'] . "'" . $update_sql);
        } else {
            $this->db->query("INSERT INTO " . DB_PREFIX . "xlevel_history SET customer_id = '".(int)$data['customer_id']."', order_id = '".(int)$data['order_id']."', `type` = '".$data['type']."', description = '".$data['description']."', amount = '" . (float)$data['amount'] . "', is_valid = '1', date_added = NOW()");
        }
        $this->setLevel($data['customer_id']);
    }
    public function applyDowngrade($customer_id) {
        if (!$this->setting['general']['downgrade']) return false;
        $xlevel_customer = $this->getLevelCustomer($customer_id, true);
        if (!$xlevel_customer) return false;
        
        $current_level = $this->setting['levels'][$xlevel_customer['level_id']];
        if ($current_level['minimum']) {
            $current_earned_amount = $this->getRewardAmount($customer_id, true);
            if ($current_level['minimum'] > (float)$current_earned_amount) {
                $this->db->query("UPDATE " . DB_PREFIX . "xlevel_history SET is_valid = '0' WHERE customer_id = '" . (int)$customer_id . "'");
                $prev_level_id = $this->getTargetLevel($current_level['total'] - 1);
                $prev_level = $this->setting['levels'][$prev_level_id];
                $data = array(
                  'customer_id' => $customer_id,
                  'order_id' => 0,
                  'type' => 'downgrade',
                  'description' => $prev_level_id,
                  'amount' => $prev_level['total']
                );
                $this->addReward($data);
            }
        }
    }
    public function setLevel($customer_id) {
        $xlevel_customer = $this->getLevelCustomer($customer_id);
        $customer = $this->db->query("SELECT firstname, lastname, email FROM `" . DB_PREFIX . "customer` WHERE customer_id = '" . (int)$customer_id . "'")->row;
        $total = $this->getRewardAmount($customer_id);
        $level_id = $this->getTargetLevel((float)$total);
        $current_level = $this->setting['levels'][$level_id];

        if ($xlevel_customer) {
            $date_added = ''; 
            if ($level_id != $xlevel_customer['level_id']) {
                $date_added = " ,date_added = NOW()";
            } else if ($level_id && $this->setting['order_period']) {
                $expire_date = date('Y-m-d', strtotime("+" . $this->setting['order_period'] . " months", strtotime($xlevel_customer['date_added'])));
                if ($expire_date <= date('Y-m-d')) {
                    // expired but has a valid level, so set expire date to next period
                    $expire_date = date('Y-m-d H:i:s', strtotime("+" . $this->setting['order_period'] . " months", strtotime($xlevel_customer['date_added'])));
                    $date_added = " ,date_added = '" . $this->db->escape($expire_date) . "'";
                    $xlevel_customer['date_added'] = $expire_date;
                }
            }
            $this->db->query("UPDATE " . DB_PREFIX . "xlevel_customer SET level_id = '".(int)$level_id."', total = '" . (float)$total . "' ".$date_added." WHERE customer_id = '".(int)$customer_id."'");
        } else if ($customer_id) {
            $this->db->query("INSERT INTO " . DB_PREFIX . "xlevel_customer SET customer_id = '".(int)$customer_id."', level_id = '".(int)$level_id."', total = '" . (float)$total . "', date_added = NOW()");
        }
        
        /* Send email if set yes */
        if ($xlevel_customer && $level_id != $xlevel_customer['level_id']) {
            $replacers = array();
            $replacers[] = $customer['firstname'];
            $replacers[] = $customer['lastname'];
            $replacers[] = $current_level['name'];
            $replacers[] = $current_level['discount_format'];
            $replacers[] = '<img src="' . $current_level['badge'] . '"/>';
            $replacers[] = $this->config->get('config_name');
            $replacers[] = $this->ocm->common->getSiteURL();
            if ($this->setting['general']['notify_customer'] && $this->setting['email_title'] && $this->setting['email_content']) {
                if ($customer['email']) {
                    $this->processEmail($customer['email'], $this->setting['email_title'], $this->setting['email_content'], $replacers);
                }
            }
            if ($this->setting['general']['notify_admin'] && $this->setting['general']['admin_email'] && $this->setting['admin_subject'] && $this->setting['admin_content']) {
                if ($customer['email']) {
                    $this->processEmail($this->setting['general']['admin_email'], $this->setting['admin_subject'], $this->setting['admin_content'], $replacers);
                }
            }
        }
        return array(
           'customer_id' => $customer_id,
           'level_id'    => $level_id,
           'total'       => $total,
           'date_added'  => $xlevel_customer ? $xlevel_customer['date_added'] : time()
        );
    }
    public function getLevel($customer_id) {
        $xlevel_customer = $this->getLevelCustomer($customer_id);
        $refresh_required = $this->customer->isLogged() && !isset($this->session->data['xlevel_upgrade']);
        if (isset($this->request->get['nc'])) { //manually from browser 
            $refresh_required = true;
        }
        if (!$xlevel_customer || $refresh_required) {
           $xlevel_customer = $this->setLevel($customer_id);
           $this->session->data['xlevel_upgrade'] = true;
        }

        $levels = $this->setting['levels'];
        $level_id = $xlevel_customer['level_id'];
        $current_level = $levels[$level_id];
        $next_level_id = $this->getTargetLevel($current_level['total'], true);
        if ($next_level_id) {
           $next_level = $levels[$next_level_id];
        }
        $retain_total = 0;
        if ($this->setting['general']['downgrade'] && $current_level['minimum']) {
            $next_earned_amount = $this->getRewardAmount($customer_id, false, $xlevel_customer['date_added']);
            $retain_total = $current_level['minimum'] - $next_earned_amount;
        }

        $valid_to = $this->setting['order_period'] ? date($this->setting['general']['date_format'], strtotime("+" . $this->setting['order_period'] . " months", strtotime($xlevel_customer['date_added']))) : false;
        $downgrade_on = $this->setting['general']['downgrade'] ? date($this->setting['general']['date_format'], strtotime("+" . $this->setting['downgrade_period'] . " months", strtotime($xlevel_customer['date_added']))) : false;

        return array(
          'level_id'      => $level_id,
          'level'         => $current_level['name'],
          'discount'      => $current_level['discount_format'],
          'valid_to'      => $valid_to,
          'badge'         => $current_level['badge'],
          'next_level_id' => $next_level_id,
          'next_level'    => $next_level_id ? $next_level['name'] : false,
          'next_discount' => $next_level_id ? $next_level['discount_format'] : false,
          'next_amount'   => $next_level_id ? $this->currency->format(($next_level['total'] - $xlevel_customer['total']), $this->config->get('config_currency')) : false,
          'retain_total'  => $retain_total > 0  ? $this->currency->format($retain_total, $this->config->get('config_currency')) : false,
          'downgrade_on'  =>  $downgrade_on,
          'total'         => $this->currency->format($xlevel_customer['total'], $this->config->get('config_currency'))
        );
    }
    public function getRewardAmount($customer_id, $downgrade = false, $start_date = false) {
        if (!$customer_id) return 0;
        $sql = "SELECT SUM(amount) AS total FROM " . DB_PREFIX . "xlevel_history WHERE is_valid = '1' AND customer_id='".(int)$customer_id."'";
        if ($start_date) {
            $sql .= " AND date_added > '" . $this->db->escape($start_date) . "'";
        } else if ($downgrade) {
            $sql .= $this->setting['downgrade_period_sql'];
        } else if (!$this->setting['general']['downgrade']) { // consider period if downgrade is disabled
            $sql .= $this->setting['order_period_sql'];
        }
        return $this->db->query($sql)->row['total'];
    }
    public function processEmail($to, $subject, $message, $replacers) {
        if (!$subject || !$message) {
            return;
        }
        $placeholders = array('{firstName}', '{lastName}', '{level}', '{discount}', '{badge}', '{storeName}', '{storeURL}');
        $message = str_replace($placeholders, $replacers, $message);
        $subject = str_replace($placeholders, $replacers, $subject); 

        $data = array();
        $data['to'] = $to;
        $data['subject'] = $subject;
        $data['message'] = $message;
        $this->ocm->sendMail($data);
    }
    public function getRewards($customer_id, $data = array()) {
        $this->load->language('extension/xlevel/module/xlevel');
        $sql = "SELECT * FROM " . DB_PREFIX . "xlevel_history WHERE customer_id = '".(int)$customer_id."' ORDER BY date_added";

        if (isset($data['order']) && ($data['order'] == 'ASC')) {
            $sql .= " ASC";
        } else {
            $sql .= " DESC";
        }
        if (isset($data['start']) || isset($data['limit'])) {
            if ($data['start'] < 0) {
                $data['start'] = 0;
            }
            if ($data['limit'] < 1) {
                $data['limit'] = 20;
            }
            $sql .= " LIMIT " . (int)$data['start'] . "," . (int)$data['limit'];
        }
        $results = $this->db->query($sql)->rows;
        $return = array();
        foreach ($results as $result) {
            if ($result['type'] == 'order') {
                $order_ref = defined('HTTP_CATALOG') ? $result['order_id'] : '<a href="'. $this->url->link('account/order/info', 'order_id=' . $result['order_id'], true) . ' ">'.$result['order_id'].'</a>';
                $description = sprintf($this->language->get('text_order_reward'), $order_ref);
            } else if ($result['type'] == 'downgrade') {
                $downgraded_level_id = (int)$result['description'];
                $description = 'Downgraded';
                if (isset($this->setting['levels'][$downgraded_level_id])) {
                    $downgradedt_level = $this->setting['levels'][$downgraded_level_id];
                    $description = sprintf($this->language->get('text_downgrade_to'), $downgradedt_level['name']);
                }
            } else {
               $description = $result['description'];
            }
            $return[] = array(
                'description' => $description,
                'date_added' => date($this->setting['general']['date_format'], strtotime($result['date_added'])),
                'amount'   => $this->currency->format($result['amount'], $this->config->get('config_currency'))
            );
        }
        return $return;
    }
    public function resetLevels($page, $new_only = false) {
       set_time_limit(3600);
       $_customers = array();
       $complete_status = $this->setting['general']['complete_status'];
       $total_code = $this->setting['general']['total_code'];
       $negative_total = $this->setting['general']['negative_total'];
       $customer_per_query = 100;
       $order_per_query = 200;
       $complete_order = $complete_status ? "AND o.order_status_id IN (" . implode(',', $complete_status) . ") " : '';

       $new_only = $new_only ? "WHERE customer_id NOT IN (SELECT `customer_id` FROM " . DB_PREFIX . "xlevel_customer)" : "";
       
       $sql = "SELECT customer_id FROM " . DB_PREFIX . "customer " . $new_only . " ORDER BY customer_id ASC LIMIT " . ($page * $customer_per_query) . ", " . $customer_per_query;
       $customers = $this->db->query($sql)->rows;
      
       foreach ($customers as $customer) {
            $_customer = array(
               'date_added' => false,
               'total' => 0,
               'customer_id' => $customer['customer_id']
            );
            $start = 0;
            RE_FETCH:

            $sql = "SELECT SUM(`ot`.`value`) AS total, `o`.`order_id`, `o`.`date_added` FROM " . DB_PREFIX . "order_total ot INNER JOIN `" . DB_PREFIX . "order` o ON ot.order_id = o.order_id WHERE o.customer_id = '".(int)$customer['customer_id']."' AND `code` IN ('" . implode("','", $total_code) . "') " . $complete_order .  $this->setting['order_period_sql'] . " GROUP BY o.order_id ORDER BY o.date_added ASC";
            $sql .= " LIMIT " . $start . "," . $order_per_query;
            $orders = $this->db->query($sql)->rows;
            if (!$orders) {
                $_customers[] = $_customer;
                continue;
            }

            $deducted_total = array();
            if ($negative_total) {
                $sql = "SELECT SUM(`ot`.`value`) AS total, `o`.`order_id` FROM " . DB_PREFIX . "order_total ot INNER JOIN `" . DB_PREFIX . "order` o ON ot.order_id = o.order_id WHERE o.customer_id = '".(int)$customer['customer_id']."' AND value < 0 " . $complete_order .  $this->setting['order_period_sql'] . " GROUP BY o.order_id ORDER BY o.date_added ASC";
                $sql .= " LIMIT " . $start . "," . $order_per_query;
                $deducted_orders = $this->db->query($sql)->rows;
                foreach ($deducted_orders as $deducted_order) {
                   $deducted_total[$deducted_order['order_id']] = $deducted_order['total'];
                }
            }

            $batch_root = "INSERT INTO `" .DB_PREFIX . "xlevel_history` VALUES ";
            $batch_sql = '';
            foreach ($orders as $order) {
                $order_total = $order['total'];
                if (isset($deducted_total[$order['order_id']])) {
                    $order_total += $deducted_total[$order['order_id']];
                }
                if ($order_total) {
                    $batch_sql .= ($batch_sql ? "," : "") . "(NULL, '" . (int)$customer['customer_id'] . "','" . (int)$order['order_id'] . "', 'order', 'Order Rewarded', '".(float)$order_total."', '1', '" . $this->db->escape($order['date_added']) . "')";
                    $_customer['total'] += $order_total;
                    if (!$_customer['date_added']) {
                        $_customer['date_added'] = $order['date_added'];
                    }
                }
            }
            if ($batch_sql) {
                $batch_sql = $batch_root . $batch_sql;
                $this->db->query($batch_sql);
            }
            $start += $order_per_query;
            GOTO RE_FETCH;
        }

        $batch_root = "INSERT INTO `" .DB_PREFIX . "xlevel_customer` VALUES ";
        $batch_sql = '';
        foreach ($_customers as $_customer) {
             $level_id = $this->getTargetLevel((float)$_customer['total']);
             $date_added = ($level_id || !$_customer['date_added']) ? 'NOW()' : "'".$_customer['date_added']."'"; 
             $batch_sql .= ($batch_sql ? "," : "") . "('" . (int)$_customer['customer_id'] . "','" . (int)$level_id . "', '".(float)$_customer['total']."', " . $date_added .")";
        }
        if ($batch_sql) {
            $batch_sql = $batch_root . $batch_sql;
            $this->db->query($batch_sql);
        }
        // remove customers who does not exist in customer table
        $this->db->query("DELETE FROM " . DB_PREFIX . "xlevel_customer WHERE `customer_id` NOT IN (SELECT `customer_id` FROM " . DB_PREFIX . "customer)"); 
        return count($_customers);
    }
    public function syncDowngrade() {
        if (!$this->setting['general']['downgrade']) return 0;
        set_time_limit(3600);
        $sql = "SELECT customer_id FROM " . DB_PREFIX . "xlevel_customer WHERE date_added < NOW() " . $this->setting['downgrade_validity_sql'];
        $customers = $this->db->query($sql)->rows;
        foreach ($customers as $customer) {
            $this->applyDowngrade($customer['customer_id']);
        }
        return count($customers);
    }
    public function syncLevels() {
        if (!$this->setting['general']['order_period']) return 0;
        set_time_limit(3600);
        $sql = "SELECT customer_id FROM " . DB_PREFIX . "xlevel_customer WHERE date_added < NOW() " . $this->setting['level_validity_sql'];
        $customers = $this->db->query($sql)->rows;
        foreach ($customers as $customer) {
            $this->setLevel($customer['customer_id']);
        }
        return count($customers);
    }
}