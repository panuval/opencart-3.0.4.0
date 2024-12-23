<?php
/* Commmon methods are used by both front and back */
namespace OCM;
final class Common {
    private $registry;
    private $request;
    private $config;
    public function __construct($registry) {
        $this->registry = $registry;
        $this->request = $registry->get('request');
        $this->config = $registry->get('config');
    }
    public function __get($name) {
        return $this->registry->get($name);
    }
    public function getConfig($key, $prefix = '') {
        $prefix = VERSION >= '3.0.0.0' ? $prefix .'_' : '';
        $key = $prefix . $key;
        return $this->config->get($key);
    }
    public function getExtPath($type, $name = '') {
        if (VERSION < '2.3.0.0') {
            $key = $type . '/';
        } else if (VERSION < '4.0.0.0') {
            $key = 'extension/' . $type . '/';
        } else {
            $key = 'extension/' . $name . '/' . $type . '/';
        }
        return $key;
    }
    public function getCatalogURL() {
        $store_id = $this->config->get('config_store_id');
        if ($store_id) return $this->config->get('config_url');
        $store_url = HTTP_CATALOG;
        if ($this->request->server['HTTPS'] && strpos($store_url, 'https:') === false) {
            $store_url = str_replace('http:', 'https:', $store_url);
        }
        return $store_url;
    }
    public function getSiteURL() {
        $store_id = $this->config->get('config_store_id');
        if ($store_id) return $this->config->get('config_url');
        
        $store_url = HTTP_SERVER;
        if (defined('HTTP_CATALOG')) {
            $store_url = HTTP_CATALOG;
        }
        if ($this->request->server['HTTPS'] && strpos($store_url, 'https:') === false) {
            $store_url = str_replace('http:', 'https:', $store_url);
        }
        if (defined('_JEXEC') && VERSION >= '4.0.0.0') {
            $store_url .= 'components/com_opencart/';
        }
        return $store_url;
    }
    public function getOcmBaseUrl($suffix = '', $name = '') {
        if ($suffix == 'admin' && VERSION < '4.0.0.0') {
            // try to find directory name if it was named
            if (defined("HTTP_SERVER") && defined("HTTP_CATALOG")) {
                $admin_dir = trim(str_replace(HTTP_CATALOG, "", HTTP_SERVER), "/");
                if ($admin_dir && $admin_dir !== "admin") {
                    $suffix = $admin_dir;
                }
            }
        }
        return  $this->getSiteUrl() . (VERSION >= '4.0.0.0' ? 'extension/' . $name . '/' : '') . ($suffix ? $suffix .'/' : '');
    }
    public function getVar($name, $more = array()) {
        $value = isset($this->session->data[$name]) ? $this->session->data[$name] : '';
        $value = isset($this->request->post[$name]) ? $this->request->post[$name] : $value;
        $value = isset($this->request->get[$name]) ? $this->request->get[$name] : $value;
        if (!$value && $more) {
            foreach ($more as $type => $name) {
                if ($type == 's') {
                    $value = isset($this->session->data[$name]) ? $this->session->data[$name] : $value;
                }
                if ($type == 'p') {
                    $value = isset($this->request->post[$name]) ? $this->request->post[$name] : $value;
                }
                if ($type == 'g') {
                    $value = isset($this->request->get[$name]) ? $this->request->get[$name] : $value;
                }
                if ($value) {
                    break;
                }
            }
        }
        return $value;
    }
    public function curlReq($url, $method = 'GET', $data = false, $header = array(), $param = array()) {
        $curl = curl_init();
        switch ($method) {
            case "POST":
            if (is_array($data)) {
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
            } else {
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            }  
            break; 
            default:
            if ($data) {
                $encoding = isset($param['encoding']) && $param['encoding'] == 'raw' ? PHP_QUERY_RFC3986 : PHP_QUERY_RFC1738;
                $url = rtrim($url, '?');
                $url = sprintf("%s?%s", $url, http_build_query($data, null, '&', $encoding));
            }
        }
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSLVERSION, 6);

        if (!empty($header) && is_array($header)) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header); 
        }
        if (!empty($param['auth'])) {
           curl_setopt($curl, CURLOPT_USERPWD, $param['auth']);
        }
        if (!empty($param['ua'])) {
           curl_setopt($curl, CURLOPT_USERAGENT, $param['ua']);
        }
        $result = curl_exec($curl);
        if (isset($param['debug']) && $param['debug']) {
            curl_setopt($curl, CURLOPT_HEADER, true);
            $this->log->write('Curl URL: ' . $url);
            $this->log->write('Curl Data: ' . print_r($data, true));
            $this->log->write('Curl Header: ' . print_r($header, true));
            $this->log->write('Curl Response: ' . print_r($result, true));
            $this->log->write('Curl Error: ' . curl_error($curl));
        }
        curl_close($curl);
        return $result;
    }
    public function toCurlHeader($params) {
        if (!$params) return array();
        $return = array();
        foreach ($params as $param) {
            $return[] = $param['name'] . ':' . $param['value'];
        }
        return $return;
    }
    public function toCurlData($params) {
        if (!$params) return array();
        $return = array();
        foreach ($params as $param) {
            $return[$param['name']] = $param['value'];
        }
        return $return;
    }
}