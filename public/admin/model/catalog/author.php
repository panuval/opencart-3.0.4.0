<?php
class ModelCatalogAuthor extends Model {
	public function addAuthor($data) {
		$this->db->query("INSERT INTO " . DB_PREFIX . "author SET sort_order = '" . (int)$data['sort_order'] . "', status = '" . (int)$data['status'] . "', date_modified = NOW(), date_added = NOW()");

		$author_id = $this->db->getLastId();

		if (isset($data['image'])) {
			$this->db->query("UPDATE " . DB_PREFIX . "author SET image = '" . $this->db->escape($data['image']) . "' WHERE author_id = '" . (int)$author_id . "'");
		}

		foreach ($data['author_description'] as $language_id => $value) {
			$this->db->query("INSERT INTO " . DB_PREFIX . "author_description SET author_id = '" . (int)$author_id . "', language_id = '" . (int)$language_id . "', name = '" . $this->db->escape($value['name']) . "', description = '" . $this->db->escape($value['description']) . "', meta_title = '" . $this->db->escape($value['meta_title']) . "', meta_description = '" . $this->db->escape($value['meta_description']) . "', meta_keyword = '" . $this->db->escape($value['meta_keyword']) . "'");
		}

		if (isset($data['author_store'])) {
			foreach ($data['author_store'] as $store_id) {
				$this->db->query("INSERT INTO " . DB_PREFIX . "author_to_store SET author_id = '" . (int)$author_id . "', store_id = '" . (int)$store_id . "'");
			}
		}

		if (isset($data['author_seo_url'])) {
			foreach ($data['author_seo_url'] as $store_id => $language) {
				foreach ($language as $language_id => $keyword) {
					if (!empty($keyword)) {
						$this->db->query("INSERT INTO " . DB_PREFIX . "seo_url SET store_id = '" . (int)$store_id . "', language_id = '" . (int)$language_id . "', query = 'author_id=" . (int)$author_id . "', keyword = '" . $this->db->escape($keyword) . "'");
					}
				}
			}
		}
		
		// Set which layout to use with this author
		if (isset($data['author_layout'])) {
			foreach ($data['author_layout'] as $store_id => $layout_id) {
				$this->db->query("INSERT INTO " . DB_PREFIX . "author_to_layout SET author_id = '" . (int)$author_id . "', store_id = '" . (int)$store_id . "', layout_id = '" . (int)$layout_id . "'");
			}
		}

		$this->cache->delete('author');

		return $author_id;
		
	}

	public function editAuthor($author_id, $data) {
		$this->db->query("UPDATE " . DB_PREFIX . "author SET sort_order = '" . (int)$data['sort_order'] . "', status = '" . (int)$data['status'] . "', date_modified = NOW() WHERE author_id = '" . (int)$author_id . "'");

		if (isset($data['image'])) {
			$this->db->query("UPDATE " . DB_PREFIX . "author SET image = '" . $this->db->escape($data['image']) . "' WHERE author_id = '" . (int)$author_id . "'");
		}

		$this->db->query("DELETE FROM " . DB_PREFIX . "author_description WHERE author_id = '" . (int)$author_id . "'");

		foreach ($data['author_description'] as $language_id => $value) {
			$this->db->query("INSERT INTO " . DB_PREFIX . "author_description SET author_id = '" . (int)$author_id . "', language_id = '" . (int)$language_id . "', name = '" . $this->db->escape($value['name']) . "', description = '" . $this->db->escape($value['description']) . "', meta_title = '" . $this->db->escape($value['meta_title']) . "', meta_description = '" . $this->db->escape($value['meta_description']) . "', meta_keyword = '" . $this->db->escape($value['meta_keyword']) . "'");
		}

		$this->db->query("DELETE FROM " . DB_PREFIX . "author_to_store WHERE author_id = '" . (int)$author_id . "'");

		if (isset($data['author_store'])) {
			foreach ($data['author_store'] as $store_id) {
				$this->db->query("INSERT INTO " . DB_PREFIX . "author_to_store SET author_id = '" . (int)$author_id . "', store_id = '" . (int)$store_id . "'");
			}
		}

		// SEO URL
		$this->db->query("DELETE FROM `" . DB_PREFIX . "seo_url` WHERE query = 'author_id=" . (int)$author_id . "'");

		if (isset($data['author_seo_url'])) {
			foreach ($data['author_seo_url'] as $store_id => $language) {
				foreach ($language as $language_id => $keyword) {
					if (!empty($keyword)) {
						$this->db->query("INSERT INTO " . DB_PREFIX . "seo_url SET store_id = '" . (int)$store_id . "', language_id = '" . (int)$language_id . "', query = 'author_id=" . (int)$author_id . "', keyword = '" . $this->db->escape($keyword) . "'");
					}
				}
			}
		}
		
		$this->db->query("DELETE FROM " . DB_PREFIX . "author_to_layout WHERE author_id = '" . (int)$author_id . "'");

		if (isset($data['author_layout'])) {
			foreach ($data['author_layout'] as $store_id => $layout_id) {
				$this->db->query("INSERT INTO " . DB_PREFIX . "author_to_layout SET author_id = '" . (int)$author_id . "', store_id = '" . (int)$store_id . "', layout_id = '" . (int)$layout_id . "'");
			}
		}

		$this->cache->delete('author');
	}
	
	public function deleteAuthor($author_id) {
		$this->db->query("DELETE FROM " . DB_PREFIX . "author WHERE author_id = '" . (int)$author_id . "'");
		$this->db->query("DELETE FROM " . DB_PREFIX . "author_description WHERE author_id = '" . (int)$author_id . "'");
		$this->db->query("DELETE FROM " . DB_PREFIX . "author_to_store WHERE author_id = '" . (int)$author_id . "'");
		$this->db->query("DELETE FROM " . DB_PREFIX . "author_to_layout WHERE author_id = '" . (int)$author_id . "'");
		$this->db->query("DELETE FROM " . DB_PREFIX . "product_to_author WHERE author_id = '" . (int)$author_id . "'");
		$this->db->query("DELETE FROM " . DB_PREFIX . "seo_url WHERE query = 'author_id=" . (int)$author_id . "'");

		$this->cache->delete('author');
	}

	public function getAuthor($author_id) {
		$query = $this->db->query("SELECT DISTINCT *, (SELECT keyword FROM " . DB_PREFIX . "seo_url WHERE query = 'author_id=" . (int)$author_id . "') AS keyword FROM " . DB_PREFIX . "author a LEFT JOIN " . DB_PREFIX . "author_description ad ON (a.author_id = ad.author_id) WHERE a.author_id = '" . (int)$author_id . "' AND ad.language_id = '" . (int)$this->config->get('config_language_id') . "'");
		
		return $query->row;
	}

	public function getAuthors($data = array()) {
		$sql = "SELECT * FROM " . DB_PREFIX . "author a LEFT JOIN " . DB_PREFIX . "author_description ad ON (a.author_id = ad.author_id) WHERE ad.language_id = '" . (int)$this->config->get('config_language_id') . "'";
		
		if (!empty($data['filter_name'])) {
			//$sql .= " AND ad.name LIKE '%" . $this->db->escape($data['filter_name']) . "%'";
			$sql .= " AND ad.name COLLATE UTF8_GENERAL_CI LIKE '%" . $this->db->escape($data['filter_name']) . "%'";
		}

		$sql .= " GROUP BY a.author_id";

		$sort_data = array(
			'name',
			'sort_order'
		);

		if (isset($data['sort']) && in_array($data['sort'], $sort_data)) {
			$sql .= " ORDER BY " . $data['sort'];
		} else {
			$sql .= " ORDER BY sort_order";
		}

		if (isset($data['order']) && ($data['order'] == 'DESC')) {
			$sql .= " DESC";
		} else {
			$sql .= " ASC";
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

		$query = $this->db->query($sql);

		return $query->rows;
	}

	public function getAuthorDescriptions($author_id) {
		$author_description_data = array();

		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "author_description WHERE author_id = '" . (int)$author_id . "'");

		foreach ($query->rows as $result) {
			$author_description_data[$result['language_id']] = array(
				'name'             => $result['name'],
				'meta_title'       => $result['meta_title'],
				'meta_description' => $result['meta_description'],
				'meta_keyword'     => $result['meta_keyword'],
				'description'      => $result['description']
			);
		}

		return $author_description_data;
	}

	public function getAuthorStores($author_id) {
		$author_store_data = array();

		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "author_to_store WHERE author_id = '" . (int)$author_id . "'");

		foreach ($query->rows as $result) {
			$author_store_data[] = $result['store_id'];
		}

		return $author_store_data;
	}

	public function getAuthorLayouts($author_id) {
		$author_layout_data = array();

		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "author_to_layout WHERE author_id = '" . (int)$author_id . "'");

		foreach ($query->rows as $result) {
			$author_layout_data[$result['store_id']] = $result['layout_id'];
		}

		return $author_layout_data;
	}

	public function getTotalAuthors() {
      	$query = $this->db->query("SELECT COUNT(*) AS total FROM " . DB_PREFIX . "author");

		return $query->row['total'];
	}

	public function getTotalAuthorsByLayoutId($layout_id) {
		$query = $this->db->query("SELECT COUNT(*) AS total FROM " . DB_PREFIX . "author_to_layout WHERE layout_id = '" . (int)$layout_id . "'");

		return $query->row['total'];
	}
	
	
	//AUTHOR ATTRIBUTE //
	public function addAuthorAttribute($data) {
    	foreach ($data['author_attribute'] as $language_id => $value) {
            if (isset($author_attribute_id)) {
                $this->db->query("INSERT INTO " . DB_PREFIX . "author_attribute SET author_attribute_id = '" . (int)$author_attribute_id . "', language_id = '" . (int)$language_id . "', name = '" . $this->db->escape($value['name']) . "'");
            } else {
                $this->db->query("INSERT INTO " . DB_PREFIX . "author_attribute SET language_id = '" . (int)$language_id . "', name = '" . $this->db->escape($value['name']) . "'");

                $author_attribute_id = $this->db->getLastId();
            }
        }
    }

    public function editAuthorAttribute($author_attribute_id, $data) {
	    $this->db->query("DELETE FROM " . DB_PREFIX . "author_attribute WHERE author_attribute_id = '" . (int)$author_attribute_id . "'");
		
        foreach ($data['author_attribute'] as $language_id => $value) {
            $this->db->query("INSERT INTO " . DB_PREFIX . "author_attribute SET author_attribute_id = '" . (int)$author_attribute_id . "', language_id = '" . (int)$language_id . "', name = '" . $this->db->escape($value['name']) . "'");
        }
	}

    public function deleteAuthorAttribute($author_attribute_id) {
		$this->db->query("DELETE FROM " . DB_PREFIX . "author_attribute WHERE author_attribute_id = '" . (int)$author_attribute_id . "'");
		$this->db->query("UPDATE " . DB_PREFIX . "product_to_author SET author_attribute_id = '0' WHERE author_attribute_id = '" . (int)$author_attribute_id . "'");  // new 3.0.1
    }

    public function getAuthorAttribute($author_attribute_id) {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "author_attribute WHERE author_attribute_id = '" . (int)$author_attribute_id . "' AND language_id = '" . (int)$this->config->get('config_language_id') . "'");

        return $query->row;
    }

    public function getAuthorAttributes($data = array()) {
        if ($data) {
            $sql = "SELECT * FROM " . DB_PREFIX . "author_attribute WHERE language_id = '" . (int)$this->config->get('config_language_id') . "'";

            if (isset($data['filter_name'])) {
				$sql .= " AND name COLLATE UTF8_GENERAL_CI LIKE '%" . $this->db->escape($data['filter_name']) . "%'";
			}

            $sql .= " ORDER BY name";

            if (isset($data['order']) && ($data['order'] == 'DESC')) {
                $sql .= " DESC";
            } else {
                $sql .= " ASC";
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

            $query = $this->db->query($sql);

            return $query->rows;
        } else {
            $author_attribute_data = $this->cache->get('author_attribute.' . $this->config->get('config_language_id'));

            if (!$author_attribute_data) {
                $query = $this->db->query("SELECT author_attribute_id, name FROM " . DB_PREFIX . "author_attribute WHERE language_id = '" . (int)$this->config->get('config_language_id') . "' ORDER BY name");

                $author_attribute_data = $query->rows;

                $this->cache->set('author_attribute.' . $this->config->get('config_language_id'), $author_attribute_data);
            }

            return $author_attribute_data;
        }
    }

	public function getAuthorSeoUrls($author_id) {
		$author_seo_url_data = array();

		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "seo_url WHERE query = 'author_id=" . (int)$author_id . "'");

		foreach ($query->rows as $result) {
			$author_seo_url_data[$result['store_id']][$result['language_id']] = $result['keyword'];
		}

		return $author_seo_url_data;
	}

    public function getAuthorAttributeDescriptions($author_attribute_id) {
        $author_attribute_data = array();

        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "author_attribute WHERE author_attribute_id = '" . (int)$author_attribute_id . "'");

        foreach ($query->rows as $result) {
            $author_attribute_data[$result['language_id']] = array('name' => $result['name']);
        }

        return $author_attribute_data;
    }

    public function getTotalAuthorAttributes() {
        $query = $this->db->query("SELECT COUNT(*) AS total FROM " . DB_PREFIX . "author_attribute WHERE language_id = '" . (int)$this->config->get('config_language_id') . "'");

        return $query->row['total'];
    }

}