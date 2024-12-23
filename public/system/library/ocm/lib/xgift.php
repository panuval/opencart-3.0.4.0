<?php 
namespace OCM\Lib;
use OCM as OcmCore; 
final class Xgift {
    use OcmCore\Traits\Front\Product;
    use OcmCore\Traits\Front\Validator;
    use OcmCore\Traits\Front\Crucify;
    use OcmCore\Traits\Front\Util;
    private $registry;
    private $ocm;
    private $active_xgift;
    private $xgift_text = 'Gift';
    private $mtype;
    private $xgifts = false;
    private $xmeta = array();
    public function __construct($registry) {
        $this->registry = $registry;
        $this->ocm = $this->registry->has('ocm_front') ? $this->registry->get('ocm_front') : new OcmCore\Front($this->registry);
        $this->mtype = 'module';
        $xgifts = $this->getGifts();
        $this->xgifts = $xgifts['xmethods'];
        $this->xmeta  = $xgifts['xmeta'];
        $language_id  = $this->config->get('config_language_id');
        $xgift_text   = $this->ocm->getConfig('xgift_text', $this->mtype);
        if (isset($xgift_text[$language_id])) {
            $this->xgift_text = $xgift_text[$language_id];
        }
    }
    public function __get($name) {
        return $this->registry->get($name);
    }
    public function getGifts() {
        $xgifts = $this->cache->get('ocm.xgift');
        $disable_cache = $this->ocm->getConfig('xgift_disable_cache', $this->mtype);
        if (!$xgifts || $disable_cache) {
            $xmethods = array();
            $xmeta = array(
                'category_query'  => false,
                'product_query'   => false,
                'order'           => false
            );
            $xgifts_rows = $this->db->query("SELECT * FROM `" . DB_PREFIX . "xgift` order by `sort_order` asc")->rows;
            $sort_order = array();
            foreach($xgifts_rows as $xgifts_row) {
                $method_data = $xgifts_row['method_data'];
                $method_data = json_decode($method_data, true);
                $xgift_id    = $xgifts_row['tab_id'];
                /* cache only valid discounts */
                if ($method_data && is_array($method_data) && $method_data['status']) {
                    $method_data =  $this->_resetEmptyRule($method_data);
                    $rules = $this->_findValidRules($method_data);
                    $products = $this->_findRawRate($method_data);
                    $limit = (int)$method_data['limit'];
                    if ($limit < 1) {
                        $limit = 1;
                    }
                    if ((int)$method_data['category'] > 1) {
                        $xmeta['category_query'] = true;
                    }
                    if ((int)$method_data['manufacturer_rule'] > 1) {
                        $xmeta['product_query'] = true;
                    }
                    if (!!$method_data['first']) {
                        $xmeta['order'] = true;
                    }
                    $product_rules = false;
                    if ($method_data['category'] > 1
                        || $method_data['product'] > 1
                        || $method_data['manufacturer_rule'] > 1) {
                            $product_rules = true;
                    }
                    $xmethods[$xgift_id] = array(
                        'xgift_id'        => $xgift_id,
                        'method_specific' => !!$method_data['method_specific'],
                        'product_rules'   => $product_rules,
                        'rules'           => $rules,
                        'products'        => $products,
                        'option_price'    => $method_data['option_price'],
                        'discount_option' => $method_data['discount_option'],
                        'range_type'      => $method_data['range_type'],
                        'product_or'      => !!$method_data['product_or'],
                        'start'           => !empty($rules['total']['start']) ? $rules['total']['start'] : 0,
                        'end'             => !empty($rules['total']['end']) ? $rules['total']['end'] : 0,
                        'limit'           =>  $limit
                    );
                    $sort_order[$xgift_id] = isset($rules['total']['start']) ? $rules['total']['start'] : 0;
                }
            }
            array_multisort($sort_order, SORT_DESC, $xmethods);
            $xgifts = array('xmeta' => $xmeta, 'xmethods' => $xmethods);
            $this->cache->set('ocm.xgift', $xgifts);
        }
        return $xgifts;
   }
   /* Warning: Looping hell, don't call itself as it is using getTotals */
   public function getActiveGift() {
        if ($this->active_xgift) return $this->active_xgift;
        $require_to_get = array();
        $products = array();
        $limit = 0;
        $xgift_id = 0;
        $require = false;
        if (!$this->ocm->getConfig('xgift_status', $this->mtype)) {
           return false;
        }
        $xgifts = $this->xgifts;
        if (!$xgifts) {
            return false;
        }
        if ($this->customer->isLogged()) {
            $customer_group_id = $this->customer->getGroupId();
        } else if(isset($this->session->data['customer']) && isset($this->session->data['customer']['customer_group_id']) && $this->session->data['customer']['customer_group_id']) {
            $customer_group_id = $this->session->data['customer']['customer_group_id'];
        } else {
            $customer_group_id = 0;
        }

        $cart_products = $this->cart->getProducts();
        $this->xmeta['ignore'] = '<xg>';
        $_cart_data =  $this->getProductProfile($cart_products, $this->xmeta);

        $compare_with = array();
        $compare_with['store_id'] = $this->config->get('config_store_id');
        $compare_with['customer_group_id'] = $customer_group_id;
        $compare_with['customer_id'] = $this->customer->getId();
        $compare_with['time'] = date('G');
        $compare_with['date'] = date('Y-m-d');
        $compare_with['day'] = date('w');
        $compare_with['product'] = $_cart_data['product'];
        $compare_with['category'] = $_cart_data['category'];
        $compare_with['manufacturer'] = $_cart_data['manufacturer'];
        
        foreach($xgifts as $xgift) {
            $rules = $xgift['rules'];
            $product_or = $xgift['product_or'];
            $need_specified = $xgift['product_rules'] && $xgift['method_specific'];
            if ($need_specified) {
                $this->_adjustMultiValues($rules, $_cart_data['products']);
                if ($product_or) {
                    $this->_adjustProductsOr($rules, $_cart_data['products']);
                }
                $applicable_cart = $this->_getApplicableProducts($rules, $_cart_data);
                $method_specific_data = $this->_getMethodSpecificData($need_specified, $rules, $applicable_cart, $_cart_data, false);
                $compare_with['total'] = $method_specific_data[$xgift['range_type']];
            } else {
                $compare_with['total'] = $_cart_data[$xgift['range_type']];
            }

            /* first order checking */
            $compare_with['first'] = true; // rule false value is false
            if ($this->xmeta['order']) {
                if ($this->customer->isLogged()) {
                    $order_row = $this->db->query("SELECT product_option_value_id FROM `" . DB_PREFIX . "order_option` op INNER JOIN `" . DB_PREFIX . "order` o ON op.order_id = o.order_id WHERE op.value like '<xg>%' and product_option_value_id='" . (int)$xgift['xgift_id'] . "' and o.order_status_id != 0 and customer_id = '" . (int)$this->customer->getId() . "' LIMIT 1")->row;
                    $compare_with['first'] = !$order_row;
                }
            }

            
            $limit = (int)$xgift['limit'];
            $alive_or_dead = $this->_crucify($rules, $compare_with, $product_or);
            if ($alive_or_dead['status']) {
                $xgift_id = $xgift['xgift_id'];
                $products = $xgift['products'];
                break; // just process first gift products
            } else if ($xgift['start'] && count($alive_or_dead['debugging']) == 1 && isset($alive_or_dead['debugging']['total'])) {
               $diff = $xgift['start'] - $compare_with['total'];
                if ($diff > 0) {
                    $require_to_get[] = array(
                        'diff' => $diff,
                        'limit' => $limit
                    );
                }
            }
        }
        /* Set how much cutomer need to get a gift offer */
        if (!$products) {
            $limit = 0;
            if ($require_to_get) {
                $sort_order = array();
                foreach ($require_to_get as $key => $gift) {
                    $sort_order[$key] = $gift['diff'];
                }
                array_multisort($sort_order, SORT_ASC, $require_to_get);
                $require = $require_to_get[0];
            }
        }

         // adjust limit to max product selctions if it is more than one gift product. TO DO need to add qnty in calcuation 
        if (count($products) > 1 &&  $limit > count($products)) {
            $limit = count($products);
        }

        $this->active_xgift = array(
            'products' => $products,
            'limit'    => $limit,
            'require'  => $require,
            'xgift_id' => $xgift_id
        );
        return $this->active_xgift;
   }
   private function _findRawRate($data) {
       $products = array();
       foreach($data['products'] as $product_id => $discount) {
            $discount = trim(trim($discount), '-');
            $overwrite = strpos($discount, '=') !== false;
            if ($overwrite) {
                $discount = str_replace('=', '', $discount);
            }
            if (substr($discount, -1) == '%') {
                $percent = true;
                $discount = rtrim($discount,'%');
                $value = (float)$discount / 100;
            } else {
                $percent = false;
                $value = (float)$discount;
            }
            $products[$product_id] = array('percent' => $percent, 'value' => $value, 'overwrite' => $overwrite);
        }
        return $products;
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
        if ($data['customer_all'] != 1) {
            $false_value = ($data['customer_rule'] == 'inclusive') ? false : true;
            $rules['customers'] = array(
                'type' => 'in_array',
                'product_rule' => false,
                'address_rule' => false,
                'value' => $data['customers'],
                'compare_with' => 'customer_id',
                'false_value' => $false_value
            );
        }
        if ($data['first']) {
            $rules['first'] = array(
                'type' => 'equal',
                'product_rule' => false,
                'address_rule' => false,
                'value' => '',
                'compare_with' => 'first',
                'false_value' => false
            );
        }
        if ((int)$data['product'] > 1) {
            $rules['product'] = array(
                'type' => 'function',
                'func' => '_validateProduct',
                'product_rule' => true,
                'address_rule' => false,
                'value' => $data['product_product'],
                'compare_with' => 'product',
                'rule_type' => $data['product'],
                'false_value' => false
            );
        }
        if ((int)$data['category'] > 1) {
            $rules['category'] = array(
                'type' => 'function',
                'func' => '_validateProduct',
                'product_rule' => true,
                'address_rule' => false,
                'value' => $data['product_category'],
                'compare_with' => 'category',
                'rule_type' => $data['category'],
                'false_value' => false
            );
        }
        if ((int)$data['manufacturer_rule'] > 1) {
            $rules['manufacturer'] = array(
                'type' => 'function',
                'func' => '_validateProduct',
                'product_rule' => true,
                'address_rule' => false,
                'value' => $data['manufacturer'],
                'compare_with' => 'manufacturer',
                'rule_type' => $data['manufacturer_rule'],
                'false_value' => false
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
        if ((float)$data['range_end']) {
            $rules['total'] = array(
                'type' => 'in_between',
                'product_rule' => false,
                'address_rule' => false,
                'start' => (float)$data['range_start'],
                'end' => (float)$data['range_end'],
                'compare_with' => 'total'
            );
        }
        return $rules;
    }
    private function _resetEmptyRule($data) {
        $rules = array(
            'store'             => 'store_all',
            'days'              => 'days_all',
            'customer_group'    => 'customer_group_all',
            'customers'         => 'customer_all',
            'product_category'  => 'category',
            'product_product'   => 'product',
            'manufacturer'      => 'manufacturer_rule'
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
        if (!isset($data['limit'])) $data['limit'] = 1;
        if (!isset($data['method_specific'])) $data['method_specific'] = '';
        if (!isset($data['first'])) $data['first'] = '';
        /* sanitize products params  */
        if (!isset($data['products'])) $data['products'] = array();
        if (empty($data['discount_option'])) $data['discount_option'] = array();
        if (!empty($data['range_start']) && empty($data['range_end'])) $data['range_end'] = PHP_INT_MAX;
        if (empty($data['product_or'])) $data['product_or'] = '';
        if (empty($data['customer_rule'])) $data['customer_rule'] = '';
        return $data;
    }
    public function getGiftById($xgift_id) {
        $xgifts = $this->xgifts;
        if (!$xgifts) {
            return false;
        }
        foreach($xgifts as $xgift) {
            if ($xgift['xgift_id'] == $xgift_id) {
                break;
            }
        }
        return $xgift;
    }
    public function isValidGiftProduct($product_id, $xgift_id) {
        $xgift = $this->getGiftById($xgift_id);
        if ($xgift && $xgift['products'] && isset($xgift['products'][$product_id])) {
           return $xgift;
        }
        return false;
    }
    public function getProductPrice($product_id, $xgift_id) {
        $rates = array();
        $xgift = $this->getGiftById($xgift_id);
        if ($xgift && $xgift['products'] && isset($xgift['products'][$product_id])) {
            $rates = $xgift['products'][$product_id];
        }
        if ($rates) {
            $rates['option_price'] = $xgift['option_price'];
            $rates['discount_option'] = $xgift['discount_option'];
        }
        return $rates;
    }
    /* Cart related Methods */
    /* Delete product if gift_id is different or found abnornal quantity */
    public function clean($active_xgift_id, $limit = 0) {
        $is_clean = false;
        $gifts = array();
        $active_xgift_id = (int)$active_xgift_id;
        $rows = $this->ocm->getCartProducts();
        $gift_in_cart = 0;
        foreach ($rows as $cart) {
            $option = $cart['option'];
            if (isset($option['xgift'])) {
                // no gift_id was set, so lets set from cart product
                if ($active_xgift_id === -1) {
                    $active_xgift_id = (int)$option['xgift'];
                    $xgift = $this->getGiftById($active_xgift_id);
                    $limit = (int)$xgift['limit'];
                   
                }
                if ((int)$option['xgift'] !== $active_xgift_id) {
                    $this->ocm->deleteCartProduct($cart['cart_id']);
                    $is_clean = true;
                } else {
                    $gifts[] = $cart['cart_id'];
                }
                $gift_in_cart += $cart['quantity'];
            }
        }
        if ($limit && $gift_in_cart > $limit) {
            foreach ($gifts as $cart_id) {
                $this->ocm->deleteCartProduct($cart_id);
                $is_clean = true;
            }
        }
        return $is_clean;
    }
    public function onCartProducts() {
        $this->clean(-1);
    }
    public function onCartUpdate($cart_id) {
        $cart = $this->ocm->getCartProductById($cart_id);
        $option = isset($cart['option']) ? $cart['option'] : array();
        return isset($option['xgift']) ? array('stop' => true) : false;
    }
    public function getCartGiftProducts($xgift_id) {
        $return = array();
        $products = array();
        $total = 0;
        $xgift = $this->getGiftById($xgift_id);
        if ($xgift) {
            $xgift_id = (int)$xgift['xgift_id'];
            $rows = $this->ocm->getCartProducts(true);
            foreach ($rows as $cart) {
                $option = $cart['option'];
                if (isset($option['xgift']) && (int)$option['xgift'] == $xgift_id) {
                    $products[] = $cart['product_id'];
                    $total += $cart['quantity'];
                }
            }
        }
        return array("products" => $products, "total" => $total);
    }
    public function applyCartPrice($data) {
        return $this->getGiftPrice($data['cart'], $data['product'], $data['special'], $data['option_data'], $data['option_price']);
    }
    public function getOptionPrice($price, $product_id, $prefix = '+', $option_value_id = 0) {
        $xgift = $this->getActiveGift();
        if (!$xgift) {
            return false;
        }
        $rates = $this->getProductPrice($product_id, $xgift['xgift_id']);
        $option_discount_req = $rates && ($rates['option_price'] == 'all' || ($rates['option_price'] == 'some' && in_array($option_value_id, $rates['discount_option'])));
        if ($option_discount_req) {
            return $this->applyOptionDiscount($price, $rates);
        } else {
            return false;
        }
    }
    public function applyOptionDiscount($price, $rates) {
        if (!(float)$price || !$rates) {
            return false;
        }
        $return = array();
        $amount = $rates['percent'] ? ($rates['value'] * $price) : $rates['value'];
        $return['price'] = $price - $amount;
        if ($return['price'] < 0) {
            $return['price'] = 0;
        }
        return $return;
    }
    public function getGiftPrice($cart, $product, $special, $option_data, $option_price = 0) {
        $return = array();
        $product_id = (int)$cart['product_id'];
        $option = $cart['option'];
        if ($this->ocm->isAdmin()) {
            foreach ($option as $key => $value) {
                if (!is_array($value)) {
                  $value = $this->ocm->html_decode($value);
                  if (strpos($value, '<xg>') !== false) {
                     $option['xgift'] = $key;
                  }
                }
            }
        }
        if (!isset($option['xgift']) || !$option['xgift']) {
            return false;
        }
        $xgift_id = $option['xgift'];
        $rates = $this->getProductPrice($product_id, $xgift_id);
        if ($rates) {
            $original_price = !empty($special) ? (float)$special : (float)$product['price'];
            $return['price'] = $this->getDiscountedPrice($original_price, $rates);
            // adjust rewward price if it has
            if (isset($cart['reward'])) {
                $reward = $rates['percent'] ? ($rates['value'] * $cart['reward']) : $rates['value'];
                $return['reward'] = $cart['reward'] - $reward;
            }
            /* Calulate option price */
            $_product_options = array();
            if (is_array($option_data) && $option_data) {
                foreach ($option_data as $each) {
                    if (!empty($each['option_value_id'])) {
                        $_product_options[] = $each['option_value_id'];
                    }
                }
            }
            $option_discount_req = $option_price && $_product_options && ($rates['option_price'] == 'all' || ($rates['option_price'] == 'some' && array_intersect($_product_options, $rates['discount_option'])));
            if ($option_discount_req) {
                $_option_price = $this->applyOptionDiscount($option_price, $rates);
                if ($_option_price !== false) {
                    $return['option_price'] = $_option_price['price'];
                }
            }

            $option_data[] = array(
                'product_option_id'       => $xgift_id,
                'product_option_value_id' => $xgift_id,
                'option_id'               => $xgift_id,
                'option_value_id'         => $xgift_id,
                'name'                    => '',
                'value'                   => '<xg>' . $this->xgift_text . '</xg>',
                'type'                    => 'text',
                'price'                   => 0,
                'price_prefix'            => '+'
            );
            $return['option_data'] = $option_data;
        }
        return $return ? $return : false;
    }
    public function getDiscountedPrice($price, $rate) {
        $amount = $rate['percent'] ? ($rate['value'] * $price) : $rate['value'];
        $return = isset($rate['overwrite']) && $rate['overwrite'] ? $amount : $price - $amount;
        if ($return < 0) {
            $return = 0;
        }
        return $return;
    }
}