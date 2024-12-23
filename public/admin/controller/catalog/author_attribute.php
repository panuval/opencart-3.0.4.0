<?php
//==============================================
// Author Module
// Author 	: OpenCartBoost
// Email 	: support@opencartboost.com
// Website 	: http://www.opencartboost.com
//==============================================
class ControllerCatalogAuthorAttribute extends Controller {
	private $error = array();

	public function index() {
		$this->language->load('catalog/author_attribute');

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('catalog/author');
		
		$this->getList();
	}
	
	public function add(){
		$this->language->load('catalog/author_attribute');

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('catalog/author');

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validateForm()) {
			$this->model_catalog_author->addAuthorAttribute($this->request->post);

			$this->session->data['success'] = $this->language->get('text_success');

			$url = '';

			if (isset($this->request->get['sort'])) {
				$url .= '&sort=' . $this->request->get['sort'];
			}

			if (isset($this->request->get['order'])) {
				$url .= '&order=' . $this->request->get['order'];
			}

			if (isset($this->request->get['page'])) {
				$url .= '&page=' . $this->request->get['page'];
			}

			$this->response->redirect($this->url->link('catalog/author_attribute', 'user_token=' . $this->session->data['user_token'] . $url, true)); 
		}

		$this->getForm();
	}
	
	public function edit(){
		$this->language->load('catalog/author_attribute');
		
		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('catalog/author');
		
		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validateForm()) {
			$this->model_catalog_author->editAuthorAttribute($this->request->get['author_attribute_id'], $this->request->post);

			$this->session->data['success'] = $this->language->get('text_success');

			$url = '';

			if (isset($this->request->get['sort'])) {
				$url .= '&sort=' . $this->request->get['sort'];
			}

			if (isset($this->request->get['order'])) {
				$url .= '&order=' . $this->request->get['order'];
			}

			if (isset($this->request->get['page'])) {
				$url .= '&page=' . $this->request->get['page'];
			}

			$this->response->redirect($this->url->link('catalog/author_attribute', 'user_token=' . $this->session->data['user_token'] . $url, true));
		}

		$this->getForm();
	}
	
	public function delete(){
		$this->language->load('catalog/author_attribute');

		$this->document->setTitle($this->language->get('heading_attribute_title'));

		$this->load->model('catalog/author');

		if (isset($this->request->post['selected']) && $this->validateDelete()) {
			foreach ($this->request->post['selected'] as $author_attribute_id) {
				$this->model_catalog_author->deleteAuthorAttribute($author_attribute_id);
			}

			$this->session->data['success'] = $this->language->get('text_success');

			$url = '';

			if (isset($this->request->get['sort'])) {
				$url .= '&sort=' . $this->request->get['sort'];
			}

			if (isset($this->request->get['order'])) {
				$url .= '&order=' . $this->request->get['order'];
			}

			if (isset($this->request->get['page'])) {
				$url .= '&page=' . $this->request->get['page'];
			}

			$this->response->redirect($this->url->link('catalog/author_attribute', 'user_token=' . $this->session->data['user_token'] . $url, true));
		}
		
		$this->getList();
	}
	
	public function getList() {
		if (isset($this->request->get['sort'])) {
			$sort = $this->request->get['sort'];
		} else {
			$sort = 'name';
		}

		if (isset($this->request->get['order'])) {
			$order = $this->request->get['order'];
		} else {
			$order = 'ASC';
		}

		if (isset($this->request->get['page'])) {
			$page = $this->request->get['page'];
		} else {
			$page = 1;
		}

		$url = '';

		if (isset($this->request->get['sort'])) {
			$url .= '&sort=' . $this->request->get['sort'];
		}

		if (isset($this->request->get['order'])) {
			$url .= '&order=' . $this->request->get['order'];
		}

		if (isset($this->request->get['page'])) {
			$url .= '&page=' . $this->request->get['page'];
		}

		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('catalog/author_attribute', 'user_token=' . $this->session->data['user_token'] . $url, true)
		);

		$data['author'] = $this->url->link('catalog/author', 'user_token=' . $this->session->data['user_token'] . $url, true);
		$data['add_attribute'] = $this->url->link('catalog/author_attribute/add', 'user_token=' . $this->session->data['user_token'] . $url, true);
		$data['delete_attribute'] = $this->url->link('catalog/author_attribute/delete', 'user_token=' . $this->session->data['user_token'] . $url, true);
		$data['cancel'] = $this->url->link('catalog/author', 'user_token=' . $this->session->data['user_token'] . $url, true);
		
		$data['author_attributes'] = array();

		$filter_data = array(
			'sort'  => $sort,
			'order' => $order,
			'start' => ($page - 1) * $this->config->get('config_limit_admin'),
			'limit' => $this->config->get('config_limit_admin')
		);

		$author_attribute_total = $this->model_catalog_author->getTotalAuthorAttributes();

		$results = $this->model_catalog_author->getAuthorAttributes($filter_data);

		foreach ($results as $result) {
			$data['author_attributes'][] = array(
				'author_attribute_id' 	=> $result['author_attribute_id'],
				'name'        			=> $result['name'],
				'edit'        			=> $this->url->link('catalog/author_attribute/edit', 'user_token=' . $this->session->data['user_token'] . '&author_attribute_id=' . $result['author_attribute_id'] . $url, true),
				'delete'      			=> $this->url->link('catalog/author_attribute/delete', 'user_token=' . $this->session->data['user_token'] . '&author_attribute_id=' . $result['author_attribute_id'] . $url, true)
			);
		}

		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}

		if (isset($this->session->data['success'])) {
			$data['success'] = $this->session->data['success'];

			unset($this->session->data['success']);
		} else {
			$data['success'] = '';
		}

		if (isset($this->request->post['selected'])) {
			$data['selected'] = (array)$this->request->post['selected'];
		} else {
			$data['selected'] = array();
		}

		$url = '';

		if ($order == 'ASC') {
			$url .= '&order=DESC';
		} else {
			$url .= '&order=ASC';
		}

		if (isset($this->request->get['page'])) {
			$url .= '&page=' . $this->request->get['page'];
		}

		$data['sort_name'] = $this->url->link('catalog/author_attribute', 'user_token=' . $this->session->data['user_token'] . '&sort=name' . $url, true);

		$url = '';

		if (isset($this->request->get['sort'])) {
			$url .= '&sort=' . $this->request->get['sort'];
		}

		if (isset($this->request->get['order'])) {
			$url .= '&order=' . $this->request->get['order'];
		}
		
		$pagination = new Pagination();
		$pagination->total = $author_attribute_total;
		$pagination->page = $page;
		$pagination->limit = $this->config->get('config_limit_admin');
		$pagination->url = $this->url->link('catalog/author_attribute/getList', 'user_token=' . $this->session->data['user_token'] . $url . '&page={page}', true);

		$data['pagination'] = $pagination->render();

		$data['results'] = sprintf($this->language->get('text_pagination'), ($author_attribute_total) ? (($page - 1) * $this->config->get('config_limit_admin')) + 1 : 0, ((($page - 1) * $this->config->get('config_limit_admin')) > ($author_attribute_total - $this->config->get('config_limit_admin'))) ? $author_attribute_total : ((($page - 1) * $this->config->get('config_limit_admin')) + $this->config->get('config_limit_admin')), $author_attribute_total, ceil($author_attribute_total / $this->config->get('config_limit_admin')));

		$data['sort'] = $sort;
		$data['order'] = $order;
		
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('catalog/author_attribute_list', $data));
	}

	public function getForm() {
		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}

		if (isset($this->error['name'])) {
			$data['error_name'] = $this->error['name'];
		} else {
			$data['error_name'] = array();
		}

		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('catalog/author_attribute', 'user_token=' . $this->session->data['user_token'], true)
		);
		
		
		if (!isset($this->request->get['author_attribute_id'])) {
			$data['action'] = $this->url->link('catalog/author_attribute/add', 'user_token=' . $this->session->data['user_token'], true);
		} else {
			$data['action'] = $this->url->link('catalog/author_attribute/edit', 'user_token=' . $this->session->data['user_token'] . '&author_attribute_id=' . $this->request->get['author_attribute_id'], true);
		}

		$data['cancel'] = $this->url->link('catalog/author_attribute', 'user_token=' . $this->session->data['user_token'], true);

		if (isset($this->request->get['author_attribute_id']) && ($this->request->server['REQUEST_METHOD'] != 'POST')) {
			$author_attribute_info = $this->model_catalog_author->getAuthorAttributes($this->request->get['author_attribute_id']);
		}

		$data['user_token'] = $this->session->data['user_token'];

		$this->load->model('localisation/language');

		$data['languages'] = $this->model_localisation_language->getLanguages();

		if (isset($this->request->post['author_attribute'])) {
			$data['author_attribute'] = $this->request->post['author_attribute'];
		} elseif (isset($this->request->get['author_attribute_id'])) {
			$data['author_attribute'] = $this->model_catalog_author->getAuthorAttributeDescriptions($this->request->get['author_attribute_id']);
		} else {
			$data['author_attribute'] = array();
		}

		$this->load->model('design/layout');

		$data['layouts'] = $this->model_design_layout->getLayouts();

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('catalog/author_attribute_form', $data));
	}
	
	public function autocomplete() {
		$json = array();

		if (isset($this->request->get['filter_name'])) {
			$this->load->model('catalog/author');

			$filter_data = array(
				'filter_name' => $this->request->get['filter_name'],
				'sort'        => 'name',
				'order'       => 'ASC',
				'start'       => 0,
				'limit'       => 5
			);

			$results = $this->model_catalog_author->getAuthorAttributes($filter_data);

			foreach ($results as $result) {
				$json[] = array(
					'author_attribute_id'		=> $result['author_attribute_id'], 
					'author_attribute_name' 	=> strip_tags(html_entity_decode($result['name'], ENT_QUOTES, 'UTF-8'))
				);
			}		
		}

		$sort_order = array();

		foreach ($json as $key => $value) {
			$sort_order[$key] = $value['author_attribute_name'];
		}

		array_multisort($sort_order, SORT_ASC, $json);

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
	
	protected function validateForm() {
		if (!$this->user->hasPermission('modify', 'catalog/author_attribute')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		foreach ($this->request->post['author_attribute'] as $language_id => $value) {
			if ((utf8_strlen($value['name']) < 3) || (utf8_strlen($value['name']) > 32)) {
				$this->error['name'][$language_id] = $this->language->get('error_name');
			}
		}
		
		if ($this->error && !isset($this->error['warning'])) {
			$this->error['warning'] = $this->language->get('error_warning');
		}

		return !$this->error;
	}
	
	protected function validateDelete() {
		if (!$this->user->hasPermission('modify', 'catalog/author_attribute')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

        return !$this->error;
	}

}