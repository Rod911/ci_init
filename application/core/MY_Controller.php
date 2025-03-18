<?php
defined('BASEPATH') or exit('No direct script access allowed');

class MY_Controller extends CI_Controller {
	public $data = [];
	public $popover_btn = "";
	public $action_field = true;
	public $init_dtable = true;

	public function __construct() {
		parent::__construct();
		$this->load->helper('inflector');
		$this->load->library('Excel_export');
		$this->popover_btn = '<button class="btn btn-$3 btn-border btn-sm btn-rounded json-format txn-id-btn" type="button" data-toggle="popover" data-content=\'$2\' data-label="$1" data-original-title="$1 <span class=\'close \'>&times;</span">$1</button>';
		$this->data['config'] = $this->TemplateModel->get_config();
	}

	protected function _config_template(TemplateConfig $options, string $option) {
		$this->data['form_template'] = $this->TemplateModel->{$options->form_template}();
		$this->data['view_template'] = $this->TemplateModel->{$options->view_template}();
		$this->TemplateModel->verify_access($options->access, 'view_data');
		$this->TemplateModel->set_validation($this->data['form_template']);
		$config_items = array_column(array_filter(
			$this->data['form_template'],
			function ($val) {
				return ($val['validation'] ?? true);
			}
		), 'name');
		$config_icons = (method_exists("TemplateModel", "{$option}_img_config")) ? $this->TemplateModel->{"{$option}_img_config"}() : [];
		if (method_exists($this, "config_{$option}_edit")) {
			$this->data['message'] = $this->session->flashdata('message');
			$this->data['edit'] = $this->{"config_{$option}_edit"}();
			if ($this->data['edit']['flash_message'] ?? false) {
				$this->data['message'] = $this->data['edit']['flash_message'];
			}
			if ($this->data['edit']['config_readonly_inputs'] ?? null) {
				foreach ($this->data['form_template'] as &$template_row) {
					if (in_array($template_row['name'], $this->data['edit']['config_readonly_inputs'])) {
						$template_row['readonly'] = true;
					}
				}
			}
		} else {
			$this->data['edit'] = $this->TemplateModel->get_config($config_items);
		}
		$input_tables = array_filter(
			array_column($this->data['form_template'], null, 'name'),
			function ($val) {
				return $val['type'] == 'input-table' || $val['type'] == 'image-list' || $val['type'] == 'list';
			}
		);
		foreach ($input_tables as $ti => $input_table) {
			if (($input_table['table'] ?? "") != "") {
				$this->data['edit'][$input_table['name']] = $this->TemplateModel->get_edit_map($input_table['table'], null);
			}
		}
		$select_widgets = array_filter(
			array_column($this->data['form_template'], null, 'name'),
			function ($val) {
				return $val['type'] == 'select-widget' && ($val['multiple'] ?? false) == true;
			}
		);
		foreach ($select_widgets as $sw => $select_widget) {
			$this->data['edit'][$select_widget['name']] = array_column(
				$this->TemplateModel->get_edit_map($select_widget['table'], null),
				($select_widget['field'] ?? $select_widget['name']),
			);
		}
		if ($this->form_validation->run()) {
			$form_template = array_column($this->TemplateModel->{$options->form_template}(), null, 'name');
			$post_data = $this->input->post();
			foreach ($config_icons as $icon => $path) {
				$post_data[$icon] = $this->data['edit'][$icon] ?? "";
				$img_form_template = $form_template[$icon];
				$accept = $img_form_template['accept'];
				if (in_array('jpeg', $accept)) {
					$accept[] = 'jpg';
					$accept[] = 'jpe';
				}
				$accept = join("|", $accept);
				$icon_image = $this->TemplateModel->save_image($icon, $path, $accept, null, $post_data[$icon]);
				if ($icon_image) {
					$post_data[$icon] = $icon_image;
				}
			}
			if (method_exists($this, "config_{$option}_submit")) {
				$this->{"config_{$option}_submit"}($post_data);
			} else {
				$this->TemplateModel->set_config($config_items, $post_data);
				$input_tables = array_filter(
					array_column($this->data['form_template'], null, 'name'),
					function ($val) {
						return $val['type'] == 'input-table' || $val['type'] == 'image-list' || $val['type'] == 'list';
					}
				);
				foreach ($input_tables as $ti => $input_table) {
					if (($input_table['table'] ?? "") != "") {
						$fields = array_column(array_filter(
							$this->TemplateModel->{$input_table['fields']}(),
							function ($val) {
								return !($val['ignore_field'] ?? false);
							}
						), 'name');
						$this->TemplateModel->save_table_map($input_table['table'], null, null, $fields);
					}
				}
				$select_widgets = array_filter(
					array_column($this->data['form_template'], null, 'name'),
					function ($val) {
						return $val['type'] == 'select-widget' && ($val['multiple'] ?? false) == true;
					}
				);
				foreach ($select_widgets as $si => $select_widget) {
					if (($select_widget['table'] ?? "") != "") {
						$fields = [$select_widget['field'] ?? $select_widget['name']];
						$this->TemplateModel->save_table_map($select_widget['table'], null, null, $fields);
					}
				}
			}
			$status = $this->db->error()['code'] == 0;
			$alert = $status ? $this->TemplateModel->show_alert('suc', 'Successfully updated') : $this->TemplateModel->show_alert('err', 'Failed to update');
			$this->session->set_flashdata('message', $alert);
			redirect(base_url(uri_string()));
		}
		$this->template('templates/add_template', $this->data);
	}

	protected function _view_template(TemplateConfig $options) {
		$this->data['message'] = $this->session->flashdata('message');
		$this->TemplateModel->verify_access($options->access, 'view_data');
		$this->data['table_template'] = $this->TemplateModel->{$options->table_template}();
		$this->data['view_template'] = $this->TemplateModel->{$options->view_template}();
		$this->data['filter_data'] = $this->input->get('filter');
		$this->template('templates/view_template', $this->data);
	}

	protected function _add_template(TemplateConfig $options, ?callable $edit_map = null, ?callable $edit_prefill = null) {
		$this->data['message'] = $this->session->flashdata('message');
		$this->data['edit'] = $this->TemplateModel->get_edit_row($options->table, '', $options->id);
		$this->data['form_template'] = $this->TemplateModel->{$options->form_template}($this->data['edit'][$options->id] ?? "");
		$this->data['view_template'] = $this->TemplateModel->{$options->view_template}();
		if ($edit_prefill) {
			$this->data['edit'] = $edit_prefill($this->data['edit']);
		}
		if ($this->data['edit']) {
			if ($edit_map) {
				$this->data['edit'] = $edit_map($this->data['edit']);
			}
			$this->TemplateModel->verify_access($options->access, 'edit_data');
		} else {
			$this->TemplateModel->verify_access($options->access, 'add_data');
		}
		$this->template('templates/add_template', $this->data);
	}

	protected function _sort_template(TemplateConfig $options) {
		$this->data['message'] = $this->session->flashdata('message');
		$this->data['view_template'] = $this->TemplateModel->{$options->view_template}();
		$default_sort_order = "ASC";
		$table_sort = $this->TemplateModel->{$options->table_template}()['sort_order'] ?? [$options->id, $default_sort_order];
		$img_fields = $this->TemplateModel->{$options->table_template}()['img_fields'] ?? [];
		$form_template  = array_column($this->TemplateModel->{$options->form_template}(), null, 'name');
		if (is_array($table_sort)) {
			$sort_order = $table_sort[0];
			$sort_direction = $default_sort_order;
		} else {
			$table_sort = explode(" ", $table_sort);
			$sort_order = $table_sort[0];
			$sort_direction = $default_sort_order;
		}
		$table_alias = $options->table;
		$this->TemplateModel->verify_access($options->access, 'edit_data');
		$filter = [];
		$parent_options = [];
		$get_parent_field = "";
		if ($options->parent_field !== null) {
			$get_parent_field = $this->input->get($options->parent_field);
			$parent_options = $form_template[$options->parent_field]['options'];
			$filter[$options->parent_field] = $get_parent_field;
		}
		if ($img_fields) {
			$this->db->select(array_keys($img_fields));
		}
		$this->data['parent_field'] = $options->parent_field;
		$this->data['parent_value'] = $get_parent_field;
		$this->data['parent_options'] = $parent_options;
		$this->data['img_fields'] = $img_fields;
		$this->data['sort_list'] = $options->get_options($filter, false, "ISNULL(table.$sort_order) $sort_direction, table.$sort_order $sort_direction, table.{$options->id} $sort_direction");
		$this->template('templates/sort_template', $this->data);
	}

	protected function _submit_sort(TemplateConfig $options) {
		$view_template = $this->TemplateModel->{$options->view_template}();
		$return_url = ad_base_url($view_template['links']['view']);
		$table = $options->table;
		$sort = $this->input->post('sort');
		$sort_data = json_decode($sort);
		$update = [];
		if ($sort == '') {
			$alert = $this->TemplateModel->show_alert('', 'No Change');
			$this->session->set_flashdata('message', $alert);
			redirect($return_url);
		}
		$id = $options->id;
		foreach ($sort_data as $i => $sort_val) {
			array_push($update, [$id => $sort_val, 'sort_order' => $i]);
		}

		$status = $this->db->update_batch($table, $update, $id);
		$alert = $status >= 1 ? $this->TemplateModel->show_alert('suc', 'Successfully updated') : $this->TemplateModel->show_alert('err', 'Failed to update');
		$this->session->set_flashdata('message', $alert);
		redirect($return_url);
	}

	protected function _submit_template(TemplateConfig $options, ?callable $process_post_data = null, ?callable $after_submit = null) {
		$this->data['form_template'] = $this->TemplateModel->{$options->form_template}();
		$this->data['view_template'] = $this->TemplateModel->{$options->view_template}(false);
		$this->TemplateModel->set_validation($this->data['form_template']);
		if ($this->form_validation->run()) {
			$this->db->trans_start();
			$post_data = $this->input->post();
			if ($process_post_data) {
				// Return post data is required!
				$post_data = $process_post_data($post_data);
			}
			$update = $this->TemplateModel->save_table_data($options->table, $post_data, $this->data['form_template'], $options->id);
			if ($after_submit) {
				$update = $after_submit($post_data, $update);
			}
			$error_data = $this->db->error()['message'];
			if ($update == true) {
				$this->db->trans_complete();
			}
			$return_url = ad_base_url($this->data['view_template']['links']['view']);
			echo json_encode(['save_id' => $update, 'status' => $update !== false, 'return_url' => $return_url, 'errors' => $this->form_validation->error_array(), 'error_data' => $error_data]);
		} else {
			echo json_encode(['status' => false, 'errors' => $this->form_validation->error_array()]);
		}
	}

	public function config_wildcard($path, $option) {
		if (method_exists($path, "config_{$option}")) {
			$this->{"config_{$option}"}();
		} else {
			$option = singular($option);
			$this->_config_template($this->TemplateModel->{"{$option}_config"}, $option);
		}
	}

	public function view_wildcard($path, $option) {
		if (method_exists($path, "view_{$option}")) {
			$this->{"view_{$option}"}();
		} else {
			$option = singular($option);
			$this->_view_template($this->TemplateModel->{"{$option}_config"});
		}
	}

	public function add_wildcard($path, $option) {
		if (method_exists($path, "add_{$option}")) {
			$this->{"add_{$option}"}();
		} else {
			$option = singular($option);
			/** @var TemplateConfig */
			$config = $this->TemplateModel->{"{$option}_config"};
			$this->_add_template($config, function ($edit) use ($option, $config) {
				$input_tables = array_filter(
					array_column($this->TemplateModel->{$config->form_template}(), null, 'name'),
					function ($val) {
						return $val['type'] == 'input-table' || $val['type'] == 'image-list';
					}
				);
				foreach ($input_tables as $ti => $input_table) {
					if (($input_table['table'] ?? "") != "") {
						$edit[$input_table['name']] = $this->TemplateModel->get_edit_map($input_table['table'], $input_table['key'], ($edit[$input_table['edit_key']] ?? ""));
					}
				}
				$array_lists = array_filter(
					array_column($this->TemplateModel->{$config->form_template}(), null, 'name'),
					function ($val) {
						return ($val['type'] == 'select-widget' && ($val['multiple'] ?? false) == true) || ($val['type'] == 'list');
					}
				);
				foreach ($array_lists as $sw => $array_list) {
					$edit[($array_list['field'] ?? $array_list['name'])] = array_column(
						$this->TemplateModel->get_edit_map($array_list['table'], $array_list['key'], ($edit[$array_list['edit_key']] ?? "")),
						($array_list['field'] ?? $array_list['name']),
					);
				}
				if (method_exists($this, "{$option}_edit_map")) {
					$edit = $this->{"{$option}_edit_map"}($edit);
				}
				return $edit;
			}, function ($edit) use ($option) {
				if (method_exists($this, "{$option}_edit_prefill")) {
					$edit = $this->{"{$option}_edit_prefill"}($edit);
				}
				return $edit;
			});
		}
	}

	public function submit_wildcard($path, $option) {
		if (method_exists($path, "submit_{$option}")) {
			$this->{"submit_{$option}"}();
		} else {
			$option = singular($option);
			$this->_submit_template($this->TemplateModel->{"{$option}_config"}, function ($post_data) use ($option) {
				/** @var TemplateConfig */
				$config = $this->TemplateModel->{"{$option}_config"};
				$form_template = array_column($this->TemplateModel->{$config->form_template}(), null, 'name');
				if (method_exists("TemplateModel", "{$option}_img_config")) {
					$img_configs = $this->TemplateModel->{"{$option}_img_config"}();
					foreach ($img_configs as $img_field => $path) {
						$edit = $this->TemplateModel->{$option . "_config"}->get_row($post_data['id']);
						$file_field = $_FILES[$img_field]['name'];
						$img_form_template = $form_template[$img_field];
						$accept = $img_form_template['accept'];
						if (in_array('jpeg', $accept)) {
							$accept[] = 'jpg';
							$accept[] = 'jpe';
						}
						$accept = join("|", $accept);
						if (is_array($file_field)) {
							$images = $this->TemplateModel->save_files($img_field, $path, $accept, null, true, explode(IMG_SPLIT, $edit[$img_field] ?? ''), $post_data['old_img'][$img_field] ?? []);
							$image = join(IMG_SPLIT, array_column($images, 'image'));
						} else {
							$image = $this->TemplateModel->save_image($img_field, $path, $accept, null, $edit[$img_field] ?? null);
						}
						if ($image) {
							$post_data[$img_field] = $image;
							$_POST[$img_field] = $image;
						}
						if (!$image && isset($file_field) && !empty($file_field)) {
							echo json_encode(['status' => false, 'errors' => [$img_field => $this->upload->display_errors('', '')]]);
							die();
						}
					}
				}
				if (method_exists($this, "{$option}_process_submit")) {
					$post_data = $this->{"{$option}_process_submit"}($post_data);
				}
				$wysiwyg_editors = array_filter(
					$form_template,
					function ($val) {
						return ($val['type'] == 'wysiwyg' && ($val['wysiwyg_preview'] ?? null) != null);
					}
				);
				foreach ($wysiwyg_editors as $wysiwyg_editor) {
					$post_data[$wysiwyg_editor['wysiwyg_preview']] = wysiwyg_to_preview_text($post_data[$wysiwyg_editor['name']], $wysiwyg_editor['preview_length']);
				}
				$url_title_fields = array_filter(
					$form_template,
					function ($val) {
						return ($val['type'] == 'input' && ($val['url_title_field'] ?? null) != null);
					}
				);
				foreach ($url_title_fields as $url_title_input) {
					$post_data[$url_title_input['url_title_field']] = url_title($post_data[$url_title_input['name']], '-', true);
				}
				return $post_data;
			}, function ($post_data, $update) use ($option) {
				/** @var TemplateConfig */
				$config = $this->TemplateModel->{"{$option}_config"};
				$form_template = array_column($this->TemplateModel->{$config->form_template}(), null, 'name');
				$input_tables = array_filter(
					$form_template,
					function ($val) {
						return $val['type'] == 'input-table' || $val['type'] == 'image-list';
					}
				);
				foreach ($input_tables as $ti => $input_table) {
					if (($input_table['table'] ?? "") != "") {
						$images = [];
						$fields = array_column(array_filter(
							$this->TemplateModel->{$input_table['fields']}(),
							function ($val) use ($post_data, $input_table) {
								if ($val['type'] == 'image') {
									$old_images = $post_data['old_img'][$input_table['name']] ?? [];
									$accept = $val['accept'];
									if (in_array('jpeg', $accept)) {
										$accept[] = 'jpg';
										$accept[] = 'jpe';
									}
									$accept = join("|", $accept);
									$save_images = $this->TemplateModel->save_files($val['name'], $val['path'], $accept, null, true, $old_images, $old_images);
									$_POST[$val['name']] = array_column($save_images, 'image');
								}
								return !($val['ignore_field'] ?? false);
							}
						), 'name');
						$formatting = $input_table['formatting'] ?? [];
						$this->TemplateModel->save_table_map($input_table['table'], $input_table['key'], $update, $fields, $formatting);
					}
				}
				$array_lists = array_filter(
					$form_template,
					function ($val) {
						return ($val['type'] == 'select-widget' && ($val['multiple'] ?? false) == true) || ($val['type'] == 'list');
					}
				);
				foreach ($array_lists as $si => $array_list) {
					if (($array_list['table'] ?? "") != "") {
						$fields = [$array_list['field'] ?? $array_list['name']];
						$formatting = $array_list['formatting'] ?? [];
						$this->TemplateModel->save_table_map($array_list['table'], $array_list['key'], $update, $fields, $formatting);
					}
				}
				$saved = true;
				if (method_exists($this, "{$option}_after_submit")) {
					$saved = $this->{"{$option}_after_submit"}($post_data, $update);
				}
				return true;
			});
		}
	}

	public function sort_wildcard($path, $option) {
		if (method_exists($path, "sort_{$option}")) {
			$this->{"sort_{$option}"}();
		} else {
			$option = singular($option);
			$this->_sort_template($this->TemplateModel->{"{$option}_config"});
		}
	}

	public function submit_sort_wildcard($path, $option) {
		if (method_exists($path, "submit_sort_{$option}")) {
			$this->{"submit_sort_{$option}"}();
		} else {
			$option = singular($option);
			$this->_submit_sort($this->TemplateModel->{"{$option}_config"});
		}
	}

	public function dt_wildcard($path, $option) {
		$option = singular($option);
		if (method_exists($path, "dt_{$option}")) {
			$this->{"dt_{$option}"}();
		} else {
			if (method_exists($this, "{$option}_table")) {
				$this->{"{$option}_table"}();
			}
			/** @var TemplateConfig */
			$options = $this->TemplateModel->{"{$option}_config"};
			$table_template = $this->TemplateModel->{$options->table_template}();
			$text_fields = array_filter($table_template['text_fields']);
			$select_fields = $table_template['select_fields'] ?? "";
			$search_fields = range(1, count($text_fields) + count(explode(',', $select_fields)) - 1);
			$this->_ajaxtable_template($options, $search_fields, $select_fields);
		}
	}

	public function export_wildcard($path, $option) {
		if (method_exists($path, "export_{$option}")) {
			$this->{"export_{$option}"}();
		} else {
			$option = singular($option);
			/** @var TemplateConfig */
			$options = $this->TemplateModel->{"{$option}_config"};
			$this->TemplateModel->verify_access($options->access, 'view_data');
			$filter = $this->input->post();
			list($table_heads, $table_data) = $this->TemplateModel->{"get_{$option}_export"}($filter);
			$view_template = $this->TemplateModel->{$options->view_template}();
			$name = $view_template['head'];
			$file_name = "Download-" . url_title($name) . "-" . date(date_format);
			return @Excel_export::exportExcelTable($table_data, $table_heads, $file_name);
		}
	}

	protected function add_action_col($cols) {
		$action_col = '<div class="text-center"><div class="btn-group" role="group" aria-label="Button group">';
		foreach ($cols as $col_name => $col_value) {
			if ($col_name == 'edit') {
				$action_col .= '<a href="' . ad_base_url($col_value . '?edit=$1') . '" class="btn btn-warning btn-sm"><i class="fa fa-fw fa-pencil-alt"></i></a>';
			}
			if ($col_name == 'delete') {
				$action_col .= '<button data-id="' . $col_value . '" class="btn btn-danger btn-sm delete-record"><i class="fa fa-fw fa-trash"></i></button>';
			}
			if ($col_name == 'status') {
				$action_col .= '<button data-id="' . $col_value . '" data-status="$2" class="btn btn-success btn-sm update-status"><i class="fa fa-fw fa-check"></i></button>';
			}
		}
		$action_col .= '</div></div>';
		return $action_col;
	}

	protected function add_image_col($path, $fallback = '') {
		return '<div class="text-center"><img src="' . base_url($path . '$1') . '" data-original="$1" data-fallback="' . $fallback . '" class="dt-image-col"></div>';
	}

	protected function setSearchableColumns(array $columns) {
		foreach ($columns as $ci) {
			$_POST['columns'][$ci]['searchable'] = true;
		}
	}

	protected function generate_empty() {
		echo json_encode([
			'data' => [],
			'draw' => 0,
			'recordsFiltered' => 0,
			'recordsTotal' => 0,
		]);
	}

	protected function _ajaxtable_template(TemplateConfig $options, array $searchable_columns, string $select_fields = "") {
		$template = $this->TemplateModel->{$options->view_template}(false);
		$view_filters = $template['filter'] ?? [];
		$text_fields = array_filter($this->TemplateModel->{$options->table_template}()['text_fields'] ?? []);
		$img_fields = $this->TemplateModel->{$options->table_template}()['img_fields'] ?? [];
		$table_alias = $this->TemplateModel->{$options->table_template}()['table_alias'] ?? "a";
		$default_sort_order = "ASC";
		$table_sort = $this->TemplateModel->{$options->table_template}()['sort_order'] ?? [$options->id, $default_sort_order];
		$where = $this->TemplateModel->{$options->table_template}()['where'] ?? null;
		if (is_array($table_sort)) {
			$sort_order = $table_sort[0];
			$sort_direction = $table_sort[1] ?? $default_sort_order;
		} else {
			$table_sort = explode(" ", $table_sort);
			$sort_order = $table_sort[0];
			$sort_direction = $table_sort[1] ?? $default_sort_order;
		}
		$joins = $this->TemplateModel->{$options->table_template}()['joins'] ?? [];
		$table = $options->table;

		if (!$options->form_template) {
			$this->action_field = false;
		}

		$status_field = "";
		if ($this->action_field && $options->status_field) {
			$status_field = ", " . $table_alias . "." . $options->status_field;
		}

		$id_field = $options->id;

		if ($select_fields != "") {
			$select_fields .= ", ";
		}

		$text_fields_select = (count($text_fields) > 0) ? join(', ', array_map(function ($key, $val) use ($table_alias) {
			if (substr_count($val, '.')) return $val;
			$column_alias = $table_alias;
			$original_key = $key;
			if (substr_count($key, '.')) {
				$key = explode(".", $key);
				$column_alias = $key[0];
				$key = $key[1];
			}
			$select = $column_alias . "." . $val;
			if ($val === "DATETIME") {
				$select = 'DATE_FORMAT(' . $column_alias . "." . $key . ', "' . db_user_date_time . '")';
			}
			if ($val === "DATE") {
				$select = 'DATE_FORMAT(' . $column_alias . "." . $key . ', "' . db_user_date . '")';
			}
			if ($val === "TIME") {
				$select = 'DATE_FORMAT(' . $column_alias . "." . $key . ', "' . db_user_time . '")';
			}
			if ($val === "CASE") {
				$select = $original_key;
				$key = "";
			}
			if (is_int($key)) $key = $val;
			if (is_string($key) && $key != "") $key = " AS " . $key;
			return $select . $key;
		}, array_keys($text_fields), $text_fields)) . "," : "";

		// [Image column name => Image file path]
		$img_fields_select = (count($img_fields) > 0) ? join(', ', array_map(function ($key) use ($table_alias) {
			// No need for file path in select query
			return $table_alias . "." . $key . " AS " . $key;
		}, array_keys($img_fields))) . "," : "";

		$this->db->order_by("ISNULL($table_alias.$sort_order) $sort_direction, $table_alias.$sort_order $sort_direction, $table_alias.{$options->id} $sort_direction");
		$this->datatables
			->from($table . " $table_alias")
			->select("1 as sl, $select_fields $text_fields_select $img_fields_select $table_alias.$id_field $status_field")
			->unset_column($id_field);

		if ($where) {
			$this->datatables->where($where);
		}

		if ($this->action_field) {
			$action_btns = [
				'edit' => $template['links']['add'],
				'delete' => "$1,$table,$id_field",
			];
			if ($status_field != "") {
				$action_btns['status'] = "$1,$table,$id_field,$2,$options->status_field";
			}
			$this->datatables
				->unset_column($options->status_field)
				->add_column(
					'action',
					$this->add_action_col($action_btns),
					"$id_field, $options->status_field"
				);
		}

		foreach ($img_fields as $img_field => $img_path) {
			$this->datatables
				->edit_column($img_field, $this->add_image_col($img_path), $img_field);
		}

		foreach ($joins as $join) {
			$join_table = $join['table'];
			$join_alias = $join['alias'];
			$join_condition = $join['condition'];
			$join_type = $join['type'] ?? "";
			$this->datatables->join("$join_table $join_alias", $join_condition, $join_type);
		}

		$filter_data = $this->input->post('filter') ?? [];
		foreach ($view_filters as &$filter) {
			$filter_name = $filter['name'];
			$filter_value = $filter_data[$filter_name] ?? "";
			$filter_name = str_replace("--", ".", $filter_name);
			if (empty($filter_value)) continue;
			if ($filter['type'] == 'date') {
				$filter_value = date_format_c($filter_value);
				$filter_date_type = $filter['date_type'];
				$filter_name = explode("-", $filter_name)[0];
				$filter_name = "DATE({$filter_name}) {$filter_date_type}";
			}
			if ($filter['type'] == 'time') {
				$filter_value = time_format($filter_value);
				$filter_date_type = $filter['date_type'];
				$filter_name = explode("-", $filter_name)[0];
				$filter_name = "TIME({$filter_name}) {$filter_date_type}";
			}
			if (is_array($filter_value)) {
				$this->datatables->filter_in($filter_name, $filter_value);
			} else {
				$this->datatables->filter($filter_name, $filter_value);
			}
		}

		$this->setSearchableColumns($searchable_columns);
	}

	protected function template($template_name, $vars = array(), $return = FALSE) {
		$vars['login'] = $this->TemplateModel->verify_admin();
		$vars['login_username'] = $vars['login']['user_name'];
		$view = $this->load->view(ADMIN_VIEWS_PATH . 'includes/header', $vars, true);

		if (is_array($template_name)) {
			foreach ($template_name as $file_to_load) {
				$view .= $this->load->view(ADMIN_VIEWS_PATH . $file_to_load, $vars, true);
			}
		} else {
			$view .= $this->load->view(ADMIN_VIEWS_PATH . $template_name, $vars, true);
		}

		$view .= $this->load->view(ADMIN_VIEWS_PATH . 'includes/footer', $vars, true);
		if ($return) {
			return $view;
		}
		echo $view;
	}
}
