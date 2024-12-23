<?php
namespace OCM\Lib;
class Ocmcart extends \Cart\Cart { 
    private $ocmprice; 
    protected $config;
    protected $db;
    protected $isUpdated;
    public function __construct($registry) {
        $this->ocmprice = new Ocmprice($registry);
        $this->config = $registry->get('config');
        $this->db = $registry->get('db');
        $registry->set('ocmprice', $this->ocmprice);
        parent::__construct($registry);
    }

    protected function onCartOperation($cart_id, $operation) {
        $_ocmprice = $this->ocmprice->onCartOperation($cart_id, $operation);
        //d_qc use this update function to remove cart product, sigh! so patch
        if (isset($quantity) && !$quantity && isset($_ocmprice['stop'])) {
            unset($_ocmprice['stop']);
        }
        if ($_ocmprice && isset($_ocmprice['stop'])) {
            return false;
        }
        return true;
    } 

    /* overriden methods. Defining two extra params with default values to make it compatible with some 3rd party mods */
    public function getProducts($param1 = 1, $param2 = '') {
        $this->ocmprice->onCartProducts();
        $product_data = parent::getProducts($param1, $param2);
        
        if (VERSION >= '4.0.0.0' && $this->isUpdated) return $product_data;

        /* let's build carts and minimizing queries*/
        $carts = [];
        foreach($product_data as $cart_product) {
            $cart_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "cart WHERE cart_id = '" . (int)$cart_product['cart_id'] . "'");
            $carts[$cart_product['cart_id']] = $cart_query->row;
        }    


        foreach($product_data as &$cart_product) {
            $product_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "product WHERE product_id = '" . (int)$cart_product['product_id'] . "'");
            $price = $product_query->row['price'];
    
            /* discount quantity */
            $discount_quantity = 0;
            foreach ($product_data as $_product) {
                if ($cart_product['product_id'] == $_product['product_id']) {
                    $discount_quantity += $_product['quantity'];
                }
            }

            $product_discount_query = $this->db->query("SELECT price FROM " . DB_PREFIX . "product_discount WHERE product_id = '" . (int)$cart_product['product_id'] . "' AND customer_group_id = '" . (int)$this->config->get('config_customer_group_id') . "' AND quantity <= '" . (int)$discount_quantity . "' AND ((date_start = '0000-00-00' OR date_start < NOW()) AND (date_end = '0000-00-00' OR date_end > NOW())) ORDER BY quantity DESC, priority ASC, price ASC LIMIT 1");

            if ($product_discount_query->num_rows) {
                $price = $product_discount_query->row['price'];
            }

            // Product Specials
            $product_special_query = $this->db->query("SELECT price FROM " . DB_PREFIX . "product_special WHERE product_id = '" . (int)$cart_product['product_id'] . "' AND customer_group_id = '" . (int)$this->config->get('config_customer_group_id') . "' AND ((date_start = '0000-00-00' OR date_start < NOW()) AND (date_end = '0000-00-00' OR date_end > NOW())) ORDER BY priority ASC, price ASC LIMIT 1");

            if ($product_special_query->num_rows) {
                $price = $product_special_query->row['price'];
            }
            
            $reward = $cart_product['reward'] / $cart_product['quantity'];
            $option_data = $cart_product['option'];
            $option_price = $cart_product['price'] - $price;

            $_ocmprice_data = array(
                'price'        => &$price,
                'reward'       => &$reward,
                'option_price' => &$option_price,
                'option_data'  => &$option_data,
                'product'      => &$product_query->row,
                'quantity'     => $discount_quantity,
                'carts'        => $carts,
                'cart'         => $carts[$cart_product['cart_id']],
                'special'      => $product_special_query->row ? $product_special_query->row['price'] : 0,
                'discount'     => $product_discount_query->row ? $product_discount_query->row['price'] : 0
            );
            $this->ocmprice->applyCartPrice($_ocmprice_data);

            // overriding prices
            $cart_product['price'] = $price + $option_price;
            $cart_product['total'] = ($price + $option_price) * $cart_product['quantity'];
            $cart_product['reward'] = $reward * $cart_product['quantity'];
            $cart_product['option'] = $option_data;
            $cart_product['stock'] = (int)$product_query->row['quantity'] >= $cart_product['quantity'];
        }

        if (VERSION >= '4.0.0.0') {
            $reflection = new \ReflectionClass(\Opencart\System\Library\Cart\Cart::class);
            $data = $reflection->getProperty('data');
            $data->setAccessible(true);
            $data->setValue($this, $product_data);
            $this->isUpdated = true;
        }

        return $product_data;
    }

    public function update($cart_id, $quantity, $param1 = 1, $param2 = '') {
        if ($this->onCartOperation($cart_id, __FUNCTION__)) {
            parent::update($cart_id, $quantity, $param1, $param2);
        }
    }

    public function remove($cart_id, $param1 = 1, $param2 = '') {        
        if ($this->onCartOperation($cart_id, __FUNCTION__)) {
            parent::remove($cart_id, $param1, $param2);
        }
    }               
}