<?php 
namespace OCM\Lib;
use OCM as OcmCore; 
final class Bulkprice {
    use OcmCore\Traits\Front\Crucify;
    use OcmCore\Traits\Front\Util;
    private $registry;
    private $ocm;
    private $mtype;
    private $_cached = array();
    private $bulkprices = array();
    private $rounding;
    public function __construct($registry) {
        $this->registry = $registry;
        $this->ocm = ($ocm = $this->registry->get('ocm_front')) ? $ocm : new OcmCore\Front($this->registry);
        $this->mtype = 'module';
        $this->bulkprices = $this->getModifications();
        $this->rounding = $this->ocm->getConfig('bulkprice_rounding', $this->mtype);
        if (!$this->rounding) {
            $this->rounding = 'none';
        }
    }
    public function __get($name) {
        return $this->registry->get($name);
    }
    public function getModifications() {
        $bulkprices = $this->cache->get('ocm.bulkprice');
        if (!$bulkprices) {
            $bulkprices = array();
            $bulkprices_rows = $this->db->query("SELECT * FROM `" . DB_PREFIX . "bulkprice` order by `sort_order` asc")->rows;
            foreach($bulkprices_rows as $bulkprices_row) {
                $method_data = $bulkprices_row['method_data'];
                $tab_id = (int)$bulkprices_row['tab_id']; 
                $method_data = json_decode($method_data, true);
                /* cache only valid discounts */
                if ($method_data && is_array($method_data) && $method_data['status']) {
                    $method_data =  $this->_resetEmptyRule($method_data);
                    $rules = $this->_findValidRules($method_data);
                    $rates = $this->_findRawRate($method_data);

                    $bulkprices[$tab_id] = array(
                       'tab_id' => $tab_id,
                       'rules'  => $rules,
                       'rates'  => $rates
                    );
                }
            }
            if ($bulkprices) {
                $this->cache->set('ocm.bulkprice', $bulkprices);
            }
        }
        return $bulkprices;
    }
    private function _findRawRate($data) {
        $rates = array(
            'price'     => array(),
            'special'   => array(),
            'option'    => array(),
            'discount'  => array()
        );
        foreach ($rates as $key => $value) {
            if ($data[$key]) {
                $rates[$key] = $this->_getRate($data[$key]);
            }
        }
        return $rates;
    }
    private function _getRate($amount) {
        $return = array();
        $amount = trim(trim($amount),'+');
        if (substr($amount, 0, 1) === '-') {
            $return['sign'] = true;
        } else {
            $return['sign'] = false;
        }
        $value = trim($amount, '-');
        if (substr($value, -1) == '%') {
            $value = rtrim($value,'%');
            $return['percent'] = true;
            $return['value'] = (float)$value / 100;
        } else {
            $return['percent'] = false;
            $return['value'] = (float)$value;
        }
        return $return;
    }
    private function _getTargetFromRange($ranges, $target) {
      $target_range = array();
      foreach($ranges as $range) {
          if ($range['start'] <= $target && $target <= $range['end']) {
              $target_range = $range;
              break;
          }
      }
      return $target_range;
   }
   private function _findValidRules($data) {
        $rules = array();
        if ($data['store_all'] != 1) {
            $rules['store'] = array(
                'type' => 'in_array',
                'product_rule' => false,
                'value' => $data['store'],
                'compare_with' => 'store_id',
                'false_value' => false
            );
        }
        if ($data['customer_group_all'] != 1) {
            $rules['customer_group'] = array(
                'type' => 'in_array',
                'product_rule' => false,
                'value' => $data['customer_group'],
                'compare_with' => 'customer_group_id',
                'false_value' => false
            );
        }
        if ((int)$data['product'] > 1) {
            $value = ($data['product' ] == 2) ? false : true;
            $product_query = ($data['product' ] == 2) ? 'p.product_id IN ('.implode(',', $data['product_product']).')' : 'p.product_id NOT IN ('.implode(',', $data['product_product']).')';
            $rules['product'] = array(
                'type' => 'in_array',
                'product_rule' => true,
                'product_query' => $product_query,
                'value' => $data['product_product'],
                'compare_with' => 'product_id',
                'false_value' => $value
            );
        }
        if ((int)$data['category'] > 1) {
            $value = ($data['category' ] == 2) ? false : true;
            $product_query = ($data['category' ] == 2) ? 'category_id IN ('.implode(',', $data['product_category']).')' : 'category_id NOT IN ('.implode(',', $data['product_category']).')';
            $rules['category'] = array(
                'type' => 'intersect',
                'product_rule' => true,
                'product_query' => $product_query,
                'value' => $data['product_category'],
                'compare_with' => 'product_categories',
                'false_value' => $value
            );
        }
        if ((int)$data['manufacturer_rule'] > 1) {
            $value = ($data['manufacturer_rule' ] == 2) ? false : true;
            $product_query = ($data['manufacturer_rule' ] == 2) ? 'manufacturer_id IN ('.implode(',', $data['manufacturer']).')' : 'manufacturer_id NOT IN ('.implode(',', $data['manufacturer']).')';
            $rules['manufacturer'] = array(
                'type' => 'in_array',
                'product_rule' => true,
                'product_query' => $product_query,
                'value' => $data['manufacturer'],
                'compare_with' => 'manufacturer_id',
                'false_value' => $value
            );
        }
        if ($data['days_all'] != 1 && is_array($data['days']) && $data['days'] && count($data['days']) !== 7) {
            $rules['days'] = array(
                'type' => 'in_array',
                'product_rule' => false,
                'value' => $data['days'],
                'compare_with' => 'day',
                'false_value' => false
            );
        }
        if ($data['date_start'] != "" && $data['date_end']) {
            $rules['date'] = array(
                'type' => 'in_between',
                'product_rule' => false,
                'start' => $data['date_start'],
                'end' => $data['date_end'],
                'compare_with' => 'date'
            );
        }
        if ($data['time_start'] != "" && $data['time_end'] != "") {
            $valid_hours = array();
            $time_start = (int)$data['time_start'];
            $time_end = (int)$data['time_end'];

            if ($time_start <= $time_end) {
               for ($i = $time_start; $i < $time_end ; $i++) { 
                  $valid_hours[] = $i;
               }
            } else {
               for ($i = 0; $i < $time_end ; $i++) { 
                  $valid_hours[] = $i;
               }
               for ($i = $time_start; $i <= 23 ; $i++) { 
                  $valid_hours[] = $i;
               }
            }
            if ($valid_hours) {
                $rules['time'] = array(
                    'type' => 'in_array',
                    'product_rule' => false,
                    'address_rule' => false,
                    'value' => $valid_hours,
                    'compare_with' => 'time',
                    'false_value' => false
                );
            }
        }
        /* Special rule if only ending time and date range set */
        if ($data['date_start'] != "" && $data['date_end'] && !$data['time_start'] && $data['time_end']) {
            $valid_hours = array();
            $time_start = 0;
            $time_end = (int)$data['time_end'];
            for ($i = $time_start; $i < $time_end ; $i++) { 
                  $valid_hours[] = $i;
            }
            $rules['date_time'] = array(
                'type' => 'in_array_not_equal',
                'product_rule' => false,
                'value' => $valid_hours,
                'compare_with' => 'time',
                'not_equal_value' => $data['date_end'],
                'not_equal_with' => 'date',
                'false_value' => false
            );
        }
        return $rules;
    }
    private function _resetEmptyRule($data) {
        $rules = array(
            'store' => 'store_all',
            'customer_group' => 'customer_group_all',
            'days' => 'days_all',
            'product_category' => 'category',
            'product_product' => 'product',
            'manufacturer' => 'manufacturer_rule'
        );
        foreach ($rules as $key => $value) {
            if (!isset($data[$value])) {
                $data[$value] = '';
            }
            if (!isset($data[$key]) || !$data[$key]) {
                $data[$value] = 1;
            }
        }

        /* setting default value if not set */
        if (!isset($data['customer_group_all'])) $data['customer_group_all'] = '';
        if (!isset($data['store_all'])) $data['store_all'] = '';
        /* sanitize cost params  */
        if(!isset($data['days'])) $data['days'] = array();
        return $data;
    }
    public function applyCartPrice($data) {
        $priority = (int)$this->ocm->getConfig('bulkprice_priority', $this->mtype);
        if (!$priority) $priority = 1;

        $data['product']['special'] = $data['special'];
        $data['product']['discount'] = $data['discount'];
        $result = $this->getDiscountPrice($data['product'], $data['quantity']);

        /* Calulate option price */ // TODO - negative option price same as xdiscount
        if ($data['option_price'] && $result !== false) {
            $_option_price = $this->getOptionPrice($data['option_price'], (int)$data['product']['product_id']);
            if ($_option_price !== false) {
                $result['option_price'] = $_option_price['price'];
                $result['option_amount'] = $_option_price['amount'];
            }
        }

        $special = isset($result['special']) ? $result['special'] : $data['special'];
        $discount = isset($result['discount']) ? $result['discount'] : $data['discount'];
        if ($special && $discount) {
            if ($priority == 1) {
                $result['price'] = $special;
            } else if ($priority == 2) {
                $result['price'] = $discount;
            } else {
                $result['price'] = min($special, $discount);
            }
        }
        else if ($special) {
            $result['price'] = $special;
        }
        else if ($discount) {
            $result['price'] = $discount;
        }
        return $result;
    }
    public function getDiscountedProduct($product) {
       return $this->getDiscountPrice($product);
    }
    public function getDiscountPrice($product, $quantity = 1) {
        if (!$product || !$this->ocm->getConfig('bulkprice_status', $this->mtype)) {
           return false;
        }
        /* Let fetch quntity from post so it can work other module in case of quantity based  */
        if ($quantity == 1) {
            $_quantity = $this->ocm->common->getVar('quantity');
            $quantity = $_quantity && is_numeric($_quantity) ? $_quantity : $quantity;
        }
        $cache_key = $product['product_id'] . '_' . $quantity . '_' . $product['price'];
        //serve if it found it local cache
        if (!empty($this->_cached[$cache_key])) {
            return $this->_cached[$cache_key];
        }
        $bulkprices = $this->bulkprices;
        if (!$bulkprices) {
            return false;
        }
        $return = array();
        $amount = 0;
        if ($this->customer->isLogged()) {
            $customer_group_id = $this->customer->getGroupId();
        } else if(isset($this->session->data['customer']) && isset($this->session->data['customer']['customer_group_id']) && $this->session->data['customer']['customer_group_id']) {
            $customer_group_id = $this->session->data['customer']['customer_group_id'];
        } else {
            $customer_group_id = 0;
        }
        $compare_with = array();
        $compare_with['product_id'] = $product['product_id'];
        $compare_with['manufacturer_id'] = $product['manufacturer_id'];
        $compare_with['store_id'] = $this->config->get('config_store_id');
        $compare_with['customer_group_id'] = $customer_group_id;
        $compare_with['time'] = date('G');
        $compare_with['date'] = date('Y-m-d');
        $compare_with['day'] = date('w');
        $compare_with['product_categories'] = array();
        $category_fetch = false;
        foreach($bulkprices as $bulkprice) {
            $rules = $bulkprice['rules'];
            $_rates = $bulkprice['rates'];
            /* fetch category it is really needed */
            if (!$category_fetch && isset($rules['category'])) {
                $categories = $this->db->query("SELECT category_id FROM " . DB_PREFIX . "product_to_category WHERE product_id = '" . (int)$product['product_id'] . "'")->rows;
                foreach ($categories as $category) {
                    $compare_with['product_categories'][] = $category['category_id'];
                }
                $category_fetch = true;
            }
            $live_or_dead = $this->_crucify($rules, $compare_with);
            if ($live_or_dead['status']) {
                if ($_rates) {
                    $this->request->post['_bulkprice_rate'][$product['product_id']] = $_rates; // keep cache
                    $return['no_return'] = true;
                    $return['type'] = 'overwrite';
                    if ($_rates['price'] && $product['price']) {
                        // for this module, consider ocm_price if it is available as this is the base price
                        $original_price = isset($product['ocm_price']) ? $product['ocm_price'] : $product['price'];
                        $rates = $_rates['price'];
                        $amount = $rates['percent'] ? ($rates['value'] * $original_price) : $rates['value'];
                        $return['amount'] = $amount;
                        $return['price'] = $rates['sign'] ? ($original_price - $amount) : ($original_price + $amount);
                    }
                    if ($_rates['special'] && $product['special']) {
                        $rates = $_rates['special'];
                        $amount = $rates['percent'] ? ($rates['value'] * $product['special']) : $rates['value'];
                        $return['special'] = $rates['sign'] ? ($product['special'] - $amount) : ($product['special'] + $amount);
                    }
                    if ($_rates['discount'] && $product['discount']) {
                        $rates = $_rates['discount'];
                        $amount = $rates['percent'] ? ($rates['value'] * $product['discount']) : $rates['value'];
                        $return['discount'] = $rates['sign'] ? ($product['discount'] - $amount) : ($product['discount'] + $amount);
                    }
                    // rounding if it was set
                    if ($this->rounding !== 'none') {
                        $rounding_fn = $this->rounding;
                        if (!empty($return['price'])) {
                            $return['price'] = $rounding_fn($return['price']);   
                        }
                        if (!empty($return['special'])) {
                            $return['special'] = $rounding_fn($return['special']);   
                        }
                        if (!empty($return['discount'])) {
                            $return['discount'] = $rounding_fn($return['discount']);   
                        }
                    }
                    break; // just process first one
                }
            }
        }
        //cache locally to serve for reduntant calling
        if ($return) {
            $this->_cached[$cache_key] = $return;
        }
        return $return ? $return : false;
    }
    public function getOptionPrice($price, $product_id, $prefix = '+') {
        if (!$price || !$product_id || !isset($this->request->post['_bulkprice_rate'][$product_id]) || !$this->request->post['_bulkprice_rate'][$product_id]['option']) {
            return false;
        }
        $return = false;
        $rates = $this->request->post['_bulkprice_rate'][$product_id]['option'];
        $amount = $rates['percent'] ? ($rates['value'] * $price) : $rates['value'];
        if ($amount) {
            $return = array();
            if ($price > 0 && $prefix == '-') { // option price could be negative
                $return['price'] = $rates['sign'] ? ($price + $amount) : ($price - $amount);
            } else {
                $return['price'] = $rates['sign'] ? ($price - $amount) : ($price + $amount);
            }
            $return['amount'] = $amount;
            $return['no_return'] = true;
            $return['overwrite'] = true;
            if ($this->rounding !== 'none') {
                $rounding_fn = $this->rounding;
                $return['price'] = $rounding_fn($return['price']);
            }
        }
        return $return;
    }
    public function getQuantityDiscount($price, $product_id) {
        if (!$price || !$product_id || !isset($this->request->post['_bulkprice_rate'][$product_id]) || !$this->request->post['_bulkprice_rate'][$product_id]['discount']) {
            return false;
        }
        $return = false;
        $rates = $this->request->post['_bulkprice_rate'][$product_id]['discount'];
        $amount = $rates['percent'] ? ($rates['value'] * $price) : $rates['value'];
        if ($amount) {
            $return = array();
            $return['price'] = $rates['sign'] ? ($price - $amount) : ($price + $amount);
            $return['amount'] = $amount;
            $return['no_return'] = true;
        }
        return $return;
    }
    // from arrays (used in OC < 2.1.x)
    public function getQuantityDiscounts($rows) {
        foreach ($rows as &$row) {
            $_return = $this->getQuantityDiscount($row['price'], $row['product_id']);
            if ($_return !== false) {
                $row['price'] = $_return['price'];
            }
        }
        return $rows;
    }
}