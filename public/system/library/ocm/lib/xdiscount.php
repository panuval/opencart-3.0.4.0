<?php
namespace OCM\Lib;
use OCM as OcmCore; 
final class Xdiscount {
    use OcmCore\Traits\Front\Crucify;
    use OcmCore\Traits\Front\Util;
    private $registry;
    private $mtype;
    private $final;
    private $currency_code;
    private $_cached = array();
    private $xdiscounts = array();
    private $special_page = 'oc';
    private $on_special_page = array();
    private $rounding;
    private $ocm;
    private $ribbon;
    private $ribbon_type;
    private $disable_default;
    private $option_line;
    private $cart_qnty;
    private $negative_option;
    private $countdown;
    public function __construct($registry) {
        $this->registry = $registry;
        $this->ocm = ($ocm = $this->registry->get('ocm_front')) ? $ocm : new OcmCore\Front($this->registry);
        $this->mtype = 'module';
        $this->xdiscounts = $this->getDiscounts();
        $this->ribbon = $this->ocm->getConfig('xdiscount_ribbon', $this->mtype);
        $this->countdown = $this->ocm->getConfig('xdiscount_countdown', $this->mtype);
        $this->ribbon_type = $this->ocm->getConfig('xdiscount_ribbon_type', $this->mtype);
        $this->disable_default = $this->ocm->getConfig('xdiscount_disable_default', $this->mtype);
        $this->final = $this->ocm->getConfig('xdiscount_final', $this->mtype);
        $this->special_page = $this->ocm->getConfig('xdiscount_special_page', $this->mtype);
        $this->currency_code = isset($this->session->data['currency']) ? $this->session->data['currency'] : $this->config->get('config_currency');
        $this->option_line = !$this->ocm->getConfig('xdiscount_option_line', $this->mtype);
        $this->cart_qnty = $this->ocm->getConfig('xdiscount_cart_qnty', $this->mtype);
        $this->negative_option = $this->ocm->getConfig('xdiscount_negative_option', $this->mtype);
        $this->rounding = $this->ocm->getConfig('xdiscount_rounding', $this->mtype);
        if (!$this->rounding) {
            $this->rounding = 'none';
        }
    }

    public function __get($name) {
        return $this->registry->get($name);
    }
    public function getDiscounts() {
        $xdiscounts = $this->cache->get('ocm.xdiscount');
        $disable_cache = $this->ocm->getConfig('xdiscount_disable_cache', $this->mtype);
        if (!$xdiscounts || $disable_cache) {
            $xdiscounts = array();
            $non_quantity_discount_status = false;
            $xdiscounts_rows = $this->db->query("SELECT * FROM `" . DB_PREFIX . "xdiscount` order by `sort_order` asc")->rows;
            foreach($xdiscounts_rows as $xdiscounts_row) {
                $method_data = $xdiscounts_row['method_data'];
                $tab_id = (int)$xdiscounts_row['tab_id']; 
                $method_data = json_decode($method_data, true);
                /* cache only valid discounts */
                if ($method_data && is_array($method_data) && $method_data['status']) {
                    $method_data =  $this->_resetEmptyRule($method_data);
                    $rules = $this->_findValidRules($method_data);
                    $rates = $this->_findRawRate($method_data);

                    $offer_end = $method_data['date_end'] ? $method_data['date_end'] : 0;
                    $offer_end = ($offer_end && $method_data['time_end']) ? $offer_end . sprintf(" %02d:", $method_data['time_end']).'00:00' : $offer_end;
                    if ($offer_end) {
                        $offer_end = strtotime($offer_end);
                    }
                    if ($method_data['discount_type'] == 'flat' || $method_data['discount_type'] == 'price') {
                        $non_quantity_discount_status = true;
                    }

                    if ($method_data['discount_type'] == 'quantity'
                        && !empty($rates['ranges'])
                        && $rates['ranges'][0]['start'] > 1
                        && $non_quantity_discount_status) {
                        $method_data['special'] = false; 
                        //set it false otherwise it creates an issue on journal live price when it first see special abut quantity dsicount see afterwards. Apart from this solution Tell customer to keep quantity discounts at the top to resolve issue related to thhis   
                    }

                    $xdiscounts[$tab_id] = array(
                       'tab_id' => $tab_id,
                       'rules'  => $rules,
                       'rates'  => $rates,
                       'max'    => (float)$method_data['max'],
                       'type'    => $method_data['discount_type'],
                       'special' => !!$method_data['special'],
                       'show_discount_table' => !!$method_data['show_discount_table'],
                       'keep_default' => !!$method_data['keep_default'],
                       'out_of_stock' => !!$method_data['out_of_stock'],
                       'option_price' => !!$method_data['option_price'],
                       'discount_special' => !!$method_data['discount_special'],
                       'offer_end'    => $offer_end
                    );
                }
            }
            if ($xdiscounts) {
                $this->cache->set('ocm.xdiscount', $xdiscounts);
            }
        }
        return $xdiscounts;
    }
    public function getOfferExpiration() {
        $offer_expires = array();
        $xdiscounts = $this->xdiscounts;
        foreach($xdiscounts as $xdiscount) {
            $offer_end = $xdiscount['offer_end'];
            $offer_end = $offer_end - time();
            $offer_end = $offer_end > 0 ? $offer_end : 0;
            $offer_expires[$xdiscount['tab_id']] = $offer_end;
        }
        return $offer_expires;
    }
    private function _setDiscountTable($ranges, $option_discount, $price, $product_id) {
        $_ranges = array();
        $rounding_fn = '';
        if ($this->rounding !== 'none') {
            $rounding_fn = $this->rounding;
        }
        foreach ($ranges as $range) {
            if ($range['start'] < 1) continue;
            $_price = $range['percent'] ? ($range['value'] * $price) : $range['value'];
            $discounted_price = $price - $_price;
            if ($discounted_price >= 0) {
                if ($rounding_fn) {
                    $discounted_price = $rounding_fn($discounted_price);
                }
                $_ranges[] = array(
                    'price'    => $discounted_price,
                    'quantity' => $range['start'],
                    'option_discount' => $option_discount,
                    '_ocm_'    => true, // set a flag to identity price in 3rd party modules if needed
                    'rate'     => $range // keep rate so we can use it later
                );
            }
        }
        $this->request->post['_xdiscount_table'][$product_id] = $_ranges;
        return $_ranges ? true : false;
    }
    public function getDiscountTable($product_id, $original_discount) {
        return isset($this->request->post['_xdiscount_table'][$product_id]) ? $this->request->post['_xdiscount_table'][$product_id] : $original_discount;
    }
    public function getDiscountMeta() {
       return isset($this->request->post['_xdiscount_meta']) ? $this->request->post['_xdiscount_meta'] : array();
    }
    private function _findRawRate($data) {
        $rates = array();
        if ($data['discount_type'] == 'flat') {
            $cost = trim(trim($data['cost']), '-');
            if (substr($cost, -1) == '%') {
                $cost = rtrim($cost,'%');
                $rates['percent'] = true;
                $rates['value'] = (float)$cost / 100;
            } else {
                $rates['percent'] = false;
                $rates['value'] = (float)$cost;
            }
        } else {
           $ranges = array();
           foreach($data['ranges'] as $range) {
                $start = (float)$range['start'];
                $end = (float)$range['end'];
                $cost = trim(trim($range['cost']), '-');
                if (substr($cost, -1) == '%') {
                    $cost = rtrim($cost,'%');
                    $percent = true;
                    $value = (float)$cost / 100;
                } else {
                    $percent = false;
                    $value = (float)$cost;
                }
                if ($start && !$end) {
                    $end = PHP_INT_MAX;
                }
                $ranges[] = array('start' => $start, 'end' => $end, 'percent' => $percent, 'value' => $value);
            }
            $rates['ranges'] = $ranges;
        }
        return $rates;
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
        if ((int)$data['filter_rule'] > 1) {
            $value = ($data['filter_rule' ] == 2) ? false : true;
            $product_query = ($data['filter_rule' ] == 2) ? 'filter_id IN ('.implode(',', $data['filter']).')' : 'filter_id NOT IN ('.implode(',', $data['filter']).')';
            $rules['filter'] = array(
                'type' => 'intersect',
                'product_rule' => true,
                'product_query' => $product_query,
                'value' => $data['filter'],
                'compare_with' => 'product_filters',
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
            'filter' => 'filter_rule',
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
        if (!isset($data['show_as_special'])) $data['show_as_special'] = '';
        if (!isset($data['show_discount_table'])) $data['show_discount_table'] = '';
        if (!isset($data['keep_default'])) $data['keep_default'] = '';
        if (!isset($data['out_of_stock'])) $data['out_of_stock'] = '';
        if (!isset($data['option_price'])) $data['option_price'] = '';
        if (!isset($data['discount_special'])) $data['discount_special'] = '';
        if (!isset($data['max'])) $data['max'] = 0;
        if (!isset($data['special'])) $data['special'] = '';

        /* sanitize cost params  */
        if (empty($data['ranges'])) $data['ranges'] = array();
        if (empty($data['days'])) $data['days'] = array();
        return $data;
    }
    public function applyCartPrice($data) {
        $default_discount = array(
            'type'   => 'special',
            'amount' => 0
        );
        // some modules modify cart price but the actual product was price 0 so let set price
        if (!(float)$data['product']['price'] && (float)$data['price']) {
            $data['product']['price'] = $data['price'];
            $data['product']['ocm_price'] = $data['price'];
        }
        $default_discount['amount'] = $data['product']['price'] - $data['price'];
        if ($default_discount['amount']  > 0) {
           if ($data['special']) {
              $default_discount['type']  = 'special';
           } else if ($data['discount']) {
              $default_discount['type'] = 'discount';
           }
        }
        // prefix and eligible quantity - to-do for OC < v2.1.x
        if ($this->cart_qnty && isset($data['cart']['cart_id'])) {
            $origin_prefix = $data['cart']['cart_id'];
            $quantity = $data['cart']['quantity'];
        } else {
            $origin_prefix = '';
            $quantity = $data['quantity'];
        }
        $data['product']['origin'] = $origin_prefix . 'cart'; // cache distinguisher
        $result = $this->getDiscountPrice($data['product'], $quantity, $default_discount);
        // calculate option discount
        if ($result !== false) {
            if ($this->negative_option) {
                $_option_price = $this->getOptionPriceFromOptionData($data['option_data'], $data['product']['product_id'], $data['product']['origin']);
            } else {
               $_option_price = $this->getOptionPrice($data['option_price'], (int)$data['product']['product_id'], '+', $data['product']['origin']); 
            }
            if ($_option_price !== false) {
                $result['option_price'] = $_option_price['price'];
                $result['option_amount'] = $_option_price['amount'];
            }
        }
        return $result;
    }
    public function getDiscountedProduct($product) {
       return $this->getDiscountPrice($product);
    }
    public function getDiscountPrice($product, $quantity = 1, $default_discount = array()) {
        if (!$product || !$this->ocm->getConfig('xdiscount_status', $this->mtype)) {
           return false;
        }
        /* Let fetch quntity from post so it can work other module in case of quantity based  */
        if ($quantity == 1) {
            if (!empty($product['minimum'])) {
                $quantity = $product['minimum'];
            }
            $_quantity = $this->ocm->common->getVar('quantity');
            $quantity = $_quantity && is_numeric($_quantity) ? $_quantity : $quantity;
        }
        $xdiscounts = $this->xdiscounts;
        if (!$xdiscounts && !$this->disable_default && $this->final == 'cart') {
            return false;
        }
        $origin = isset($product['origin']) ? $product['origin'] : 'product';
        $cache_key = $origin . '_' . $product['product_id'] . '_' . $quantity . '_' . $product['price'];
        //serve if it found it local cache
        if (!empty($this->_cached[$cache_key])) {
            return $this->_cached[$cache_key];
        }
        $return = array();
        $is_product_page = false;
        if (isset($this->request->get['product_id'])) {
            $is_product_page = (int)$this->request->get['product_id'] == (int)$product['product_id'];
        } else if (isset($this->request->post['product_id']) && isset($this->request->server['HTTP_X_REQUESTED_WITH'])) {
            $is_product_page = (int)$this->request->post['product_id'] == (int)$product['product_id'];
        }

        $offer_id = 0;
        $amount = 0;
        $is_percent = false;

        $customer_group_id = $this->ocm->common->getVar('customer_group_id');
        if (!$customer_group_id && !empty($this->request->post['order_data']['customer_group_id'])) {
            $customer_group_id = $this->request->post['order_data']['customer_group_id'];
        }
        else if (!$customer_group_id && $this->customer->isLogged()) {
            $customer_group_id = $this->customer->getGroupId();
        } elseif (isset($this->session->data['customer']) && !empty($this->session->data['customer']['customer_group_id'])) {
            $customer_group_id = $this->session->data['customer']['customer_group_id'];
        } elseif (isset($this->session->data['guest']) && !empty($this->session->data['guest']['customer_group_id'])) {
            $customer_group_id = $this->session->data['guest']['customer_group_id'];
        } else if (!$customer_group_id) {
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
        $compare_with['product_filters'] = array();
        $category_fetch = false;
        $filter_fetch = false;
        $discount_applied = false;
        $is_keep_default = false;
        $special = false;
        $qnty_discount_on_special = false;
        // for x-discount, ocm_price should be the price if it is available
        $original_price = isset($product['ocm_price']) ? $product['ocm_price'] : $product['price'];
        foreach($xdiscounts as $xdiscount) {
            $type = $xdiscount['type'];
            $special = $xdiscount['special'];
            $rules = $xdiscount['rules'];
            $rates = $xdiscount['rates'];
            $max = (float)$xdiscount['max'];
            /* fetch category it is really needed */
            if (!$category_fetch && isset($rules['category'])) {
                $categories = $this->db->query("SELECT category_id FROM " . DB_PREFIX . "product_to_category WHERE product_id = '" . (int)$product['product_id'] . "'")->rows;
                foreach ($categories as $category) {
                    $compare_with['product_categories'][] = $category['category_id'];
                }
                $category_fetch = true;
            }
            /* fetch filters it is really needed */
            if (!$filter_fetch && isset($rules['filter'])) {
                $filters = $this->db->query("SELECT filter_id FROM " . DB_PREFIX . "product_filter WHERE product_id = '" . (int)$product['product_id'] . "'")->rows;
                foreach ($filters as $filter) {
                    $compare_with['product_filters'][] = $filter['filter_id'];
                }
                $filter_fetch = true;
            }
            $live_or_dead = $this->_crucify($rules, $compare_with);
            if ($live_or_dead['status']) {
                /* Don't overwrite special price if keep defualt was set yes */
                if (($type == 'flat' || $type == 'price' || $type == 'total' || $type == 'stock')
                    && $xdiscount['keep_default']
                    && isset($product['special'])
                    && $product['special']
                    && !$xdiscount['option_price']) {
                      $is_keep_default = true;
                      continue;
                }
                if ($type == 'quantity'
                    && isset($product['special'])
                    && $product['special']
                    && !$this->disable_default) {
                    if ($xdiscount['discount_special']) {
                        $original_price = $product['special'];
                        $qnty_discount_on_special = true;
                    } else {
                        $is_keep_default = true;
                        continue;
                    }
                }
                if (($type == 'flat' || $type == 'price' || $type == 'total' || $type == 'stock')
                    && isset($xdiscount['out_of_stock'])
                    && $xdiscount['out_of_stock']
                    && !$product['quantity']) {
                    $is_keep_default = true;
                    continue;
                }

                if ($type == 'quantity' && $is_product_page && $xdiscount['show_discount_table']) {
                    $this->_setDiscountTable($rates['ranges'], $xdiscount['option_price'], $original_price, $product['product_id']);
                }
                if ($type == 'quantity') {
                    $rates = $this->_getTargetFromRange($rates['ranges'], $quantity);
                } else if ($type == 'price') {
                    $rates = $this->_getTargetFromRange($rates['ranges'], $original_price);
                } else if ($type == 'total') {
                    $rates = $this->_getTargetFromRange($rates['ranges'], $original_price * $quantity);
                } else if ($type == 'stock') {
                    $rates = $this->_getTargetFromRange($rates['ranges'], $product['quantity']);
                }
                if ($rates) {
                    $amount = $rates['percent'] ? ($rates['value'] * $original_price) : $rates['value'];
                    if ($max > 0 && $amount > $max && $max < $original_price) {
                        $amount = $max;
                    }
                    if ($max > 0 && $type == 'quantity' && ($amount * $quantity) > $max) {
                        $amount = $max / $quantity;
                    }
                    $is_percent = $rates['percent'];
                    $discounted_price = $original_price - $amount;
                    if (($discounted_price >= 0 && $amount) || $xdiscount['option_price']) {
                        $return['type'] = $special ? 'special' : 'discount';
                        $return['amount'] = $amount;
                        $return['price'] = $discounted_price;
                        if (($type == 'flat' || $type == 'price' || $type == 'total')
                            && isset($product['special'])
                            && $product['special']
                            && $xdiscount['keep_default']) {
                            // reset to OC default special price
                            $return['price'] = $product['special'];
                            $return['amount'] = $amount = $original_price - $product['special'];
                        } 
                        $offer_id = $xdiscount['tab_id'];
                        $optn_cache_key = $origin . '_' . $product['product_id'];
                        $this->request->post['_xdiscount_rate'][$optn_cache_key] = array(
                            'rates' => $rates,
                            'xop'   => $xdiscount['option_price'],
                            'max'   =>  $max
                        );
                        if ($this->final == 'total') {
                            $return['on_total'] = true;
                        }
                        $return['no_return'] = true;
                        $return['reset_with'] = $qnty_discount_on_special ? 'special' : 'price';
                        $discount_applied = true;
                        $is_keep_default = false;
                    }
                    break; // just process first discount
                }
            }
        }
        // reset default promotion  even if `keep-default` could not overule it
        if ($this->disable_default && !$is_keep_default) {
            if (!$discount_applied) {
                $return['price'] = $original_price;
                $return['type'] = 'overwrite';
                $return['reset'] = true;
            } else {
                $return['reset'] = true;
            }
            if ($is_product_page && !isset($this->request->post['_xdiscount_table'][$product['product_id']])) {
                $this->request->post['_xdiscount_table'][$product['product_id']] = array();
            }
        } else if (($is_keep_default || !$discount_applied || !$xdiscounts) && $this->final == 'total' && $default_discount && $default_discount['amount']) {
             // convert default discount into total module if final is total
            $return['on_total'] = true;
            $return['price'] = $original_price;
            $return['type'] = $default_discount['type'];
            $return['amount'] = $default_discount['amount'];
        } else if (!$is_keep_default && !$discount_applied) {
            $is_keep_default = true; // for default OC products so ribbon can be shown
        }
        /* Calculate percentage for special products so it can show ribbon */
        if ($is_keep_default
            && isset($product['special'])
            && (float)$product['special']
            && (float)$original_price) {
            $amount = $original_price - $product['special'];
            $special = true;
        }
        // find ribbon text
        $ribbon = '';
        if ($this->ribbon && $amount && $special) {
            if ($this->ribbon_type == 'specific' && !$is_percent) {
                $ribbon = $this->currency->format($this->tax->calculate($amount, $product['tax_class_id'], $this->config->get('config_tax')), $this->currency_code);
            } else {
                $ribbon = (float)$original_price ? round(($amount * 100) / $original_price, 2) . '%' : 0;
            }
        }
        // rounding if it was set
        if ($this->rounding !== 'none') {
            if ($return && !empty($return['price'])) {
                $rounding_fn = $this->rounding;
                $return['price'] = $rounding_fn($return['price']);   
            }
        }
        //cache locally to serve for reduntant calling
        if ($return) {
            $this->_cached[$cache_key] = $return;
        }
        if (($offer_id || $is_keep_default) && ($this->ribbon || $this->countdown)) {
            $this->request->post['_xdiscount_meta'][$product['product_id']] = array('percent' => $ribbon, 'offer_id' => $offer_id);
        }
        return $return ? $return : false;
    }
    public function getOptionPriceFromOptionData($option_data, $product_id, $origin) {
        $return = false;
        $positive_price = 0;
        $negative_price = 0;
        $price = 0;
        $amount = 0;
        if (is_array($option_data) && $option_data) {
            foreach ($option_data as $each) {
                if ($each['price_prefix'] == '+') {
                    $positive_price += $each['price'];
                } else if ($each['price_prefix'] == '-') {
                    $negative_price -= $each['price'];
                }
            }
        }
        if ($positive_price) {
            $_option_price = $this->getOptionPrice($positive_price, (int)$product_id, '+', $origin);
            if ($_option_price !== false) {
                $price += $_option_price['price'];
                $amount += $_option_price['amount'];
            }
        }
        if ($negative_price) {
            $_option_price = $this->getOptionPrice($negative_price, (int)$product_id, '-', $origin);
            if ($_option_price !== false) {
                $price += $_option_price['price'];
                $amount += $_option_price['amount'];
            }
        }
        if ($amount) {
            $return = array();
            $return['price'] = $price;
            $return['amount'] = $amount;
            $return['no_return'] = true;
        }
        return $return;
    }
    public function getOptionPrice($price, $product_id, $prefix = '+', $origin = 'product') {
        $key = $origin . '_' . $product_id;
        $return = false;
        if (!$price
            || !$product_id
            || !isset($this->request->post['_xdiscount_rate'][$key])
            || !$this->request->post['_xdiscount_rate'][$key]['xop']) {
           return $return;
        }
        $_xdiscount_rate = $this->request->post['_xdiscount_rate'][$key];
        $rates = $_xdiscount_rate['rates'];
        $max = $_xdiscount_rate['max'];
        $xop = $_xdiscount_rate['xop'];

        $amount = $rates['percent'] ? ($rates['value'] * $price) : $rates['value'];
        if ($max > 0 && $amount > $max && $max < $price) {
            $amount = $max;
        }
        if ($amount) {
            $return = array();
            if ($prefix == '-') { // option price could be negative
                $return['price'] =  ($price + $amount);
            } else {
                $return['price'] = $price - $amount;
            }
            $return['amount'] = $amount;
            $return['strike_line'] = $this->option_line;
            $return['no_return'] = true;
            // rounding if it was set
            if ($this->rounding !== 'none') {
                $rounding_fn = $this->rounding;
                $return['price'] = $rounding_fn($return['price']);   
            }
        }
        return $return;
    }
    public function buildProductQuery() {
        $xdiscounts = $this->xdiscounts;
        $special_query = '';

        if ($this->customer->isLogged()) {
            $customer_group_id = $this->customer->getGroupId();
        } else if(isset($this->session->data['customer']) && isset($this->session->data['customer']['customer_group_id']) && $this->session->data['customer']['customer_group_id']) {
            $customer_group_id = $this->session->data['customer']['customer_group_id'];
        } else {
            $customer_group_id = 0;
        }
        $time=date('G');
        $compare_with = array();
        $compare_with['customer_group_id'] = $customer_group_id;
        $compare_with['store_id'] = $this->config->get('config_store_id');
        $compare_with['time'] = $time;
        $compare_with['date'] = date('Y-m-d');
        $compare_with['day'] = date('w');

        $discount_avail = false;
        foreach($xdiscounts as $xdiscount) {
            if ($this->on_special_page && !in_array($xdiscount['tab_id'], $this->on_special_page)) continue;
            if ($xdiscount['type'] == 'quantity' && !$xdiscount['special']) continue;
            $rules = $xdiscount['rules'];
            $live_or_dead = $this->_crucify($rules, $compare_with, false, true);
            if ($live_or_dead['status']) {
                $discount_avail = true;
                if ($special_query) $special_query = $special_query .' OR ';
                if ($live_or_dead['product_query']) {
                    $special_query .=  count($live_or_dead['product_query']) > 1 ? '(' .implode(' AND ', $live_or_dead['product_query']) .')' : implode(' AND ', $live_or_dead['product_query']);
                } else {
                   $special_query = ''; /* reset query as all products have discounts*/
                   break;
                }
            }
        }
        $oc_special = "p.product_id IN (SELECT product_id FROM " . DB_PREFIX . "product_special ps WHERE ps.customer_group_id = '" . (int)$this->config->get('config_customer_group_id') . "' AND ((ps.date_start = '0000-00-00' OR ps.date_start < NOW()) AND (ps.date_end = '0000-00-00' OR ps.date_end > NOW())))";
        if (!$discount_avail) {
            if (!$this->on_special_page && $this->special_page == 'both') {
                $special_query .= $oc_special;
            } else {
                $special_query .= 'p.product_id = -1';
            }
        } else if (!$this->on_special_page && $this->special_page == 'both' && $special_query) {
            $special_query .= " OR " . $oc_special;
        }
        // for journal, adjust if mode is OC only
        if (defined('JOURNAL3_ACTIVE')) {
            $route =  isset($this->request->get['route']) ? $this->request->get['route'] : '';
            if ($route && strpos($route,'product/special') !== false) {
                if ($this->special_page == 'oc') {
                    $special_query = $oc_special;
                }
                $who_called = (new Exception())->getTraceAsString();
                if (strpos($who_called, 'getManufacturers') !== false) {
                    $special_query = str_replace('manufacturer_id', 'm.manufacturer_id', $special_query);
                }
                // journal v >= 3.0.38 requires using alias c
                if (strpos($who_called, 'getCategories') !== false) {
                    $special_query = str_replace('category_id', 'c.category_id', $special_query);
                }
            }
        }
        $special_query = $special_query ? ' AND (' . $special_query . ')' : '';
        return $special_query;
    }
    public function setDiscountOnSpecialPage($discounts) {
        $this->on_special_page = array();
        if (is_array($discounts)) {
            foreach ($discounts as $id) {
                if ((int)$id) {
                    $this->on_special_page[] = $id;
                }
            }
        }
    }
}