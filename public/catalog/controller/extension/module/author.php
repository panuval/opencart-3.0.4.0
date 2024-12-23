<?php
//==============================================
// Author Module
// Author 	: OpenCartBoost
// Email 	: support@opencartboost.com
// Website 	: http://www.opencartboost.com
//==============================================
class ControllerExtensionModuleAuthor extends Controller {
    public function index($setting) {
        $this->load->language('extension/module/author');

		$this->load->model('catalog/product');
		$this->load->model('catalog/author');
		
		$data['display'] = $setting['display'];
		$data['type'] = $setting['type'];

		$heading_title = $setting['author_title'];
			
		if ($setting['type'] == 'author_list') {
			
			if (!empty($heading_title[$this->config->get('config_language_id')])) {
				$data['heading_title'] = $heading_title[$this->config->get('config_language_id')];
			} else {
				$data['heading_title'] = $this->language->get('heading_title');
			}
				
			$data['authors'] = array();

			$authors = $this->model_catalog_author->getAuthors();
			
			foreach ($authors as $author) {
				$data['authors'][] = array(
					'author_id'   => $author['author_id'],
					'name'        => $author['name'],
					'href'        => $this->url->link('product/author/info', 'author_id=' . $author['author_id'])
					//'href'        => $this->url->link('extension/module/author_page/info', 'author_id=' . $author['author_id'])
				);
			}
		} else {
			
			if (!empty($heading_title[$this->config->get('config_language_id')])) {
				$data['heading_title'] = $heading_title[$this->config->get('config_language_id')];
			} else {
				$data['heading_title'] = $this->language->get('heading_more_products');
			}
			
			$this->load->model('tool/image');

			$limit = $setting['limit'];
		
			$data['products'] = array();

			$results = $this->model_catalog_product->getProductFromSameAuthor($this->request->get['product_id']);
		
			$i = 0;
		
			if ($results) {
				foreach ($results as $result) {
					if ($result['image']) {
						$image = $this->model_tool_image->resize($result['image'], $setting['width'], $setting['height']);
					} else {
						$image = $this->model_tool_image->resize('placeholder.png', $setting['width'], $setting['height']);
					}

					if ($this->customer->isLogged() || !$this->config->get('config_customer_price')) {
						$price = $this->currency->format($this->tax->calculate($result['price'], $result['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);
					} else {
						$price = false;
					}

					if ((float)$result['special']) {
						$special = $this->currency->format($this->tax->calculate($result['special'], $result['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']);
					} else {
						$special = false;
					}

					if ($this->config->get('config_tax')) {
						$tax = $this->currency->format((float)$result['special'] ? $result['special'] : $result['price'], $this->session->data['currency']);
					} else {
						$tax = false;
					}

					if ($this->config->get('config_review_status')) {
						$rating = $result['rating'];
					} else {
						$rating = false;
					}
				
					$data['products'][] = array(
						'product_id'  => $result['product_id'],
						'thumb'       => $image,
						'name'        => $result['name'],
						'description' => utf8_substr(trim(strip_tags(html_entity_decode($result['description'], ENT_QUOTES, 'UTF-8'))), 0, $this->config->get('theme_' . $this->config->get('config_theme') . '_product_description_length')) . '..',
						'price'       => $price,
						'special'     => $special,
						'tax'         => $tax,
						'rating'      => $rating,
						'href'        => $this->url->link('product/product', 'product_id=' . $result['product_id']),
					);
				
					if (++$i == $limit) break;
				}
			}
		}
		
		return $this->load->view('extension/module/author', $data);
	}		
}