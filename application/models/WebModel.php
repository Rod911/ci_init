<?php

defined('BASEPATH') or exit('No direct script access allowed');

class WebModel extends CI_Model {
	/**
	 * @param ?int $limit
	 * @param ?int $page
	 * @return void
	 */
	private function paginate(?int $limit = null, int $page = 1) {
		if ($limit) {
			$this->db
				->limit($limit)
				->offset(($page - 1) * $limit);
		}
	}

	/**
	 * @return array<string,string|\db\contact_social_links[]>
	 */
	public function get_profile_config() {
		$config = $this->get_config([
			'company_name',
			'company_address',
			'company_city',
			'company_phone',
			'company_email',
			'company_website',
			PROFILE_LOGO_FIELD,
			PROFILE_FAVICON_FIELD,
		]);
		$config['contact_social_links'] = $this->db->get('contact_social_links')->result();
		return $config;
	}

	public function get_banner_images() {
		$images = $this->db
			->from('web_banners')
			->where('status', '1')
			->order_by('sort_order', 'asc')
			->get()
			->result();
		return $images;
	}

	public function get_aboutus_data() {
		$res = $this->get_config([
			'about_us_img',
			'about_us_title',
			'about_us_description',
		]);
		return $res;
	}

	/**
	 * @return \db\blogs[]
	 */
	public function get_blogs($limit = null, $page = 1, $not = null) {
		$this->paginate($limit, $page);
		if ($not != null) {
			$this->db->where('id !=', $not);
		}
		$blogs = $this->db
			->where('blog_status', '1')
			->order_by('blog_date', 'DESC')
			->from('blogs')
			->get()->result();
		return $blogs;
	}

	/**
	 * @param string $blog_url_title
	 * @return \db\blogs
	 */
	public function get_blog_details($blog_url_title) {
		$blog_details = $this->db
			->where('blog_url_title', $blog_url_title)
			->get('blogs')
			->row();
		return $blog_details;
	}

	/**
	 * @return int
	 */
	public function get_blog_count() {
		return $this->db->where(['blog_status' => 1])->count_all_results('blogs');
	}

	/**
	 * @return array<\db\albums>
	 */
	public function get_albums() {
		$albums = $this->db
			->select('a.*, i.image_url')
			->where('a.album_status', '1')
			->from('albums a')
			->join('media_images i', 'a.id = i.album_id AND i.image_status = 1 AND i.media_type = "i"')
			->group_by('a.id')
			->get()
			->result();
		return $albums;
	}

	/**
	 * @param int $gallery_id
	 * @return \dba\albums
	 */
	public function get_album($album_id) {
		$album = $this->db
			->from('albums a')
			->where('album_status', '1')
			->where('image_status', '1')
			->join('media_images i', 'i.album_id = a.id')
			->group_by('a.id')
			->where('a.id', $album_id)
			->limit(1)
			->get()
			->row();
		$album->images = $this->db
			->from('media_images')
			->where('image_status', '1')
			->where('album_id', $album_id)
			->get()
			->result();
		$album->other_albums = $this->db
			->select('a.*, i.image_url')
			->from('albums a')
			->where('album_status', '1')
			->where('a.id !=', $album_id)
			->join('media_images i', 'i.album_id = a.id AND i.image_status = 1 AND i.media_type = "i"')
			->group_by('a.id')
			->order_by('a.created_date', 'DESC')
			->order_by('i.id', 'DESC')
			->get()
			->result();
		return $album;
	}

	/**
	 * @param ?int $album
	 * @return \db\media_images[]
	 */
	public function get_gallery($album = null, $limit = null) {
		if ($album) {
			$this->db->where('album_id', $album);
		}
		$gallery = $this->db
			->where('image_status', '1')
			->where('media_type', 'i')
			->order_by('id', 'DESC')
			->get('media_images', $limit)
			->result();
		return $gallery;
	}

	public function get_careers() {
		$careers = $this->db
			->where('career_status', '1')
			->get('careers')
			->result_array();
		return $careers;
	}

	public function get_career_application($id) {
		$application = $this->db
			->select('ca.*, c.career_name, c.career_desc, c.career_desc_preview')
			->from('career_applications ca')
			->where(['ca.id' => $id])
			->join('careers c', 'c.id = ca.career_id')
			->get()
			->row_array();
		return $application;
	}

	public function get_contact_enquiry($id) {
		$enquiry = $this->db
			->from('contact_us')
			->where(['id' => $id])
			->get()
			->row_array();
		return $enquiry;
	}

	public function save_career_application($data) {
		$resume = $this->save_file('applicant-resume', RESUME_UPLOAD_PATH, 'doc|docx|pdf');
		if (is_array($resume)) {
			return $resume;
		}
		$resume_url = base_url(RESUME_UPLOAD_PATH . $resume);
		$post_data = [
			'career_id' => $data['career-option'],
			'applicant_fname' => $data['applicant-fname'],
			'applicant_lname' => $data['applicant-lname'],
			'applicant_email' => $data['applicant-email'],
			'applicant_phone' => $data['applicant-phone'],
			'applicant_resume' => $resume_url,
			'applicant_about' => $data['applicant-about'],
			'date' => date(date_time_format),
		];

		$res = $this->db->insert('career_applications', $post_data);
		$contact_data = $this->get_profile_config();
		if ($res) {
			$id = $this->db->insert_id();
			$table = $this->career_mail_table($id);
			$this->send_enquiry_mail($contact_data['profile_business_name'] . " - New Job Application Received", $table, "Job Application", "New Job Application");
		}
		return $res;
	}

	public function save_contact_form($data) {
		$post_data = [
			'contact_name' => $data['contact_name'],
			'contact_email' => $data['contact_email'],
			'contact_phone' => $data['contact_phone'],
			'contact_subject' => $data['contact_subject'],
			'contact_message' => $data['contact_message'],
			'submit_page' => $data['submit_page'],
			'contact_ip' => $this->input->ip_address(),
			'contact_date' => date_time_format('now'),
		];

		$res = $this->db->insert('contact_us', $post_data);
		if ($res) {
			$id = $this->db->insert_id();
			$table = $this->contact_mail_table($id);
			$contact_data = $this->get_profile_config();
			$this->send_enquiry_mail($contact_data['profile_business_name'] . " - New Contact Enquiry Received", $table, "Contact Enquiry", "New Contact Enquiry");
		}
		return $res;
	}

	public function save_file($field, $path = 'assets/uploads', $accept = '*', $max_size = '4096') {
		$this->load->library('upload');
		if (isset($_FILES[$field]) && $_FILES[$field]['name'] != '') {
			if (!file_exists($path)) {
				mkdir($path, 0777, true);
			}
			$config['upload_path'] = dirname($_SERVER["SCRIPT_FILENAME"]) . '/' . $path;
			$config['allowed_types'] = $accept;
			$config['max_size'] = $max_size;
			$config['encrypt_name'] = true;
			$this->upload->initialize($config);
			$upload_status = false;
			if (is_array($_FILES[$field]['name'])) {
				$upload_status = $this->upload->do_upload($field . '[]');
			} else {
				$upload_status = $this->upload->do_upload($field);
			}
			if ($upload_status) {
				$data = $this->upload->data();
				$image = $data['file_name'];
				return $image;
			}
		}
		return $this->upload->error_msg;
	}

	/**
	 * @template T
	 * @param T $config_key
	 * @return T
	 */
	public function get_config($config_key = null) {
		if ($config_key) {
			$this->db->where_in('config_key', $config_key);
		}
		$config = array_column($this->db->get('admin_config')->result_array(), 'config_value', 'config_key');
		if ($config_key != null && !is_array($config_key)) {
			return $config[$config_key] ?? null;
		}
		return $config;
	}

	private function career_mail_table($id) {
		$ap = $this->get_career_application($id);
		$enquiry_table = [
			'First Name' => $ap['applicant_fname'],
			'Last Name' => $ap['applicant_lname'],
			'Email' => $ap['applicant_email'],
			'Phone' => $ap['applicant_phone'],
			'Resume' => anchor($ap['applicant_resume'], "File"),
			'About' => nl2br($ap['applicant_about']),
			'Career Name' => $ap['career_name'],
			'Date' => user_date_time($ap['date']),
		];
		return $enquiry_table;
	}

	private function contact_mail_table($id) {
		$ap = $this->get_contact_enquiry($id);
		$contact_table = [
			'Name' => $ap['contact_name'],
			'Email' => $ap['contact_email'],
			'Phone' => $ap['contact_phone'],
			'Subject' => $ap['contact_subject'],
			'Message' => nl2br($ap['contact_message']),
			'Date' => user_date_time($ap['contact_date']),
		];
		return $contact_table;
	}

	private function send_enquiry_mail($subject, $table, $type, $message = "") {
		if ($message == "") {
			$message = "Hi Admin! New $type received.";
		}
		$profile_config = $this->get_profile_config();
		$mail_view = $this->load->view('email/new_enquiry', ['table' => $table, 'message' => $message, 'profile_config' => $profile_config], true);
		$config = $this->get_alerts_config();
		file_put_contents('mail/' . $type . ".html", $mail_view);
		if ($config == null) {
			return false;
		}
		$this->load->library('Alerts');
		$email_recipients = $this->get_alert_admin_recipients($config);
		return $this->alerts->send_mail($subject, $mail_view, $email_recipients);
	}

	/**
	 * @param int|string $_id
	 * @return array{status:bool,message:string}
	 */
	/**
	public function confirm_order_mail($_id) {
		$ = $this->get_($_id, true);
		if (!$) return ['status' => false, 'message' => " not found"];
		$profile_config = $this->get_profile_config();
		$config = $this->get_alerts_config();
		if (!$config) return ['status' => false, 'message' => "Config not found"];

		$_data = [
			'' => $,
			'profile_config' => $profile_config,
		];

		$mail_view = $this->load->view('email/', $_data, true);
		file_put_contents("mail/.html", $mail_view);
		$this->load->library('Alerts');
		$to_user_id = $ticket->customer->customer_email;
		$to_user_name = $ticket->customer->customer_name;
		$email_recipients = [
			['name' => $to_user_name, 'email' => $to_user_id],
		];
		$subject = "";
		$mailed = $this->alerts->send_mail($subject, $mail_view, $email_recipients, [], [], [$pdf_path]);
		if (!$mailed) return ['status' => false, 'message' => "Mail not sent"];

		$table = [
			'' => $->,
		];
		$mailed = $this->send_enquiry_mail(CLIENT_NAME . " - New x Received", $table, "booking", "New x Confirmed, x ID: {$x->x}");
		if (!$mailed) return ['status' => false, 'message' => "Mail not sent"];
		return ['status' => true, 'message' => "Mail sent successfully"];
	} */

	/**
	 * @return array<string,string>
	 */
	public function get_payment_config() {
		$config_items = [
			'payment_key_state',
			'razp_live_key_id',
			'razp_live_key_secret',
			'razp_live_wh_secret',
			'razp_live_account_no',
			'razp_test_key_id',
			'razp_test_key_secret',
			'razp_test_wh_secret',
			'razp_test_account_no',
		];
		$config = $this->get_config($config_items);
		$live = $config['payment_key_state'] == 'live';
		return [
			'razp_key_id' => $live ? $config['razp_live_key_id'] : $config['razp_test_key_id'],
			'razp_key_secret' => $live ? $config['razp_live_key_secret'] : $config['razp_test_key_secret'],
			'razp_wh_secret' => $live ? $config['razp_live_wh_secret'] : $config['razp_test_wh_secret'],
			'razp_account_no' => $live ? $config['razp_live_account_no'] : $config['razp_test_account_no'],
		];
	}

	public function get_alerts_config() {
		$config_items = [
			'sendmail_mode',
			'alert_to_email_id',
			'alert_from_email_id',
			'alert_from_name',
			'sendinblue_api_key',
		];
		$config = $this->get_config($config_items);
		return $config;
	}

	public function get_alert_admin_recipients($config) {
		$to_user_id = $config['alert_to_email_id'];
		if (!$to_user_id) {
			$to_user_id = $config['alert_from_email_id'] ?? '';
			$to_user_name = $config['alert_from_name'] ?? '';
			$email_recipients = [
				['name' => $to_user_name, 'email' => $to_user_id],
			];
		} else {
			$profile_config = $this->get_profile_config();
			$email_recipients = [];
			$to_user_list = explode("\r\n", $to_user_id);
			// Clean empty strings
			$to_user_list = array_filter($to_user_list);
			foreach ($to_user_list as $to_user_line) {
				$to_user_row = explode(":", trim($to_user_line));
				if ($to_user_row[0] == "") continue;
				if (count($to_user_row) > 1) {
					array_push($email_recipients, ['name' => $to_user_row[1], 'email' => $to_user_row[0]]);
				} else {
					array_push($email_recipients, ['name' => $profile_config['company_name'], 'email' => $to_user_row[0]]);
				}
			}
		}
		return $email_recipients;
	}
}
