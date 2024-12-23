<?php
namespace OCM;
final class Url {
    private $session;
    private $url;
    private $ocm_meta;
    public function __construct($registry, $meta) {
        $this->session = $registry->get('session');
        $this->url = $registry->get('url');
        $this->ocm_meta = $meta;
    }
    public function setMeta($meta) {
        $this->ocm_meta = $meta;
    }
    public function getToken() {
        $data = array();
        if (isset($this->session->data['user_token']) && VERSION >= '3.0.0.0') {
            $data['key'] = 'user_token';
            $data['value'] = $this->session->data['user_token'];
        } else if (isset($this->session->data['token'])) {
            $data['key'] = 'token';
            $data['value'] = $this->session->data['token'];
        }
        return $data;
    }
    public function getRoute($route, $non_slash_divider = false) {
        if (!$non_slash_divider && VERSION >= '4.0.0.0') {
            $extension_route = $this->ocm_meta['path'] . $this->ocm_meta['name'];
            if (strpos($route, $extension_route) !== false && $route > $extension_route) {
                $non_slash_divider = true;
            }
        }
        if ($non_slash_divider) {
            $divider = VERSION >= '4.0.0.0' ? (VERSION >= '4.0.2.0' ? '.' : '|') : '/';
            $parts = explode('/', $route);
            $method = array_pop($parts);
            $route = implode('/', $parts) . $divider . $method;
        }
        return $route;
    }
    public function link($route, $url = '', $secure = true) {
        $token = $this->getToken();
        if($token) {
            $url .= ($url ? '&' : '') . ($token['key'] . '=' . $token['value']);
        }
        return $this->url->link($this->getRoute($route), $url, $secure);
    }
    public function getExtensionURL() {
        return $this->link($this->ocm_meta['path'] . $this->ocm_meta['name'], '', true);
    }
    public function getExtensionURLWithPath($path, $url='') {
        return $this->link($this->ocm_meta['path'] . $this->ocm_meta['name'] . $path, $url, true);
    }
    public function getExtensionsURL() {
        $url = '';
        if (VERSION >= '3.0.0.0'){
            $route = 'marketplace/extension';
            $url .= 'type='.$this->ocm_meta['type'];
        } else if (VERSION >= '2.3.0.0') {
            $route = 'extension/extension';
            $url .= 'type='.$this->ocm_meta['type'];
        } else {
            $route = 'extension/' . $this->ocm_meta['type'];
        }
        return $this->link($route, $url);
    }
    public function getModificationURL() {
        $url = '';
        if (VERSION >= '3.0.0.0'){
            $route = 'marketplace/modification';
        } else {
            $route = 'extension/modification';
        }
        return $this->link($route, $url);
    }
    public function getLangImage($languages) {
        $dir = VERSION >= '2.2.0.0' ? 'language/' : 'view/image/flags/';
        foreach($languages as $index => $language) {
            $languages[$index]['image'] = $dir . (VERSION >= '2.2.0.0' ? $language['code'].'/'.$language['code'].'.png' : $language['image']);
            //joomla fix
            if (defined('_JEXEC')) { 
                $oc_url = '';
                if (VERSION >= '4.0.0.0') {
                    $oc_url = HTTP_SERVER;
                    $oc_url = str_replace('/administrator/', '/components/com_opencart/admin/', $oc_url);
                }
                else if (VERSION >= '3.0.0.0') {
                    $oc_url = $this->request->server['HTTPS'] ? HTTPS_IMAGE : HTTP_IMAGE;
                    $oc_url = str_replace('/image/', '/admin/', $oc_url);
                }
                if ($oc_url) {
                    $languages[$index]['image'] = $oc_url . $languages[$index]['image'];
                }
            }
        }
        return $languages;
    }
}