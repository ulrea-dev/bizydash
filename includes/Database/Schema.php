<?php
namespace BizyDash\Database;

defined( 'ABSPATH' ) || exit;

class Schema {

	/** Bump when the SQL below changes. Lives next to the SQL so a stale opcache can never report a newer version than it can create. */
	const VERSION = '21';

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'bizydash_' . $name;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$t       = array();
		foreach ( array( 'brands', 'clients', 'client_brands', 'services', 'client_services', 'renewals', 'charges', 'invoices', 'invoice_items', 'invoice_installments', 'payments', 'activity', 'lookups', 'client_contacts', 'team_members', 'email_log', 'projects', 'tasks', 'project_phases', 'pay_allocations', 'pay_items', 'payouts', 'expenses', 'recurring_expenses', 'contract_templates', 'contracts', 'contract_signers', 'contract_events', 'contract_otps' ) as $n ) {
			$t[ $n ] = self::table( $n );
		}

		$sql = array();

		$sql[] = "CREATE TABLE {$t['brands']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  type varchar(40) NOT NULL DEFAULT '',
  description text,
  logo_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) $charset;";

		$sql[] = "CREATE TABLE {$t['clients']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  company_name varchar(190) NOT NULL DEFAULT '',
  contact_person varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  phone varchar(60) NOT NULL DEFAULT '',
  address text,
  notes text,
  client_type varchar(40) NOT NULL DEFAULT '',
  status varchar(40) NOT NULL DEFAULT 'active',
  currency char(3) NOT NULL DEFAULT 'NGN',
  retainer_amount decimal(14,2) NOT NULL DEFAULT 0,
  retainer_period varchar(40) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY client_type (client_type)
) $charset;";

		$sql[] = "CREATE TABLE {$t['client_brands']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  brand_id bigint(20) unsigned NOT NULL DEFAULT 0,
  is_primary tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY client_brand (client_id,brand_id),
  KEY brand_id (brand_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['services']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  description text,
  default_billing_period varchar(40) NOT NULL DEFAULT '',
  default_cost decimal(14,2) NOT NULL DEFAULT 0,
  default_price decimal(14,2) NOT NULL DEFAULT 0,
  default_billing_treatment varchar(40) NOT NULL DEFAULT '',
  default_currency char(3) NOT NULL DEFAULT '',
  kind varchar(20) NOT NULL DEFAULT 'recurring',
  type varchar(10) NOT NULL DEFAULT 'service',
  pricing varchar(10) NOT NULL DEFAULT 'fixed',
  unit_label varchar(40) NOT NULL DEFAULT '',
  subject_label varchar(60) NOT NULL DEFAULT '',
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY type (type)
) $charset;";

		$sql[] = "CREATE TABLE {$t['client_services']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  service_id bigint(20) unsigned NOT NULL DEFAULT 0,
  provider varchar(190) NOT NULL DEFAULT '',
  subject varchar(190) NOT NULL DEFAULT '',
  start_date date DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  cost decimal(14,2) NOT NULL DEFAULT 0,
  client_price decimal(14,2) NOT NULL DEFAULT 0,
  quantity decimal(10,2) NOT NULL DEFAULT 1,
  unit_price decimal(14,2) NOT NULL DEFAULT 0,
  unit_cost decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  billing_treatment varchar(40) NOT NULL DEFAULT '',
  billing_period varchar(40) NOT NULL DEFAULT '',
  interval_days int(11) NOT NULL DEFAULT 0,
  next_renewal_date date DEFAULT NULL,
  notes text,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY client_id (client_id),
  KEY service_id (service_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['renewals']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_service_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  due_date date DEFAULT NULL,
  frequency varchar(40) NOT NULL DEFAULT '',
  interval_days int(11) NOT NULL DEFAULT 0,
  cost decimal(14,2) NOT NULL DEFAULT 0,
  price decimal(14,2) NOT NULL DEFAULT 0,
  quantity decimal(10,2) NOT NULL DEFAULT 1,
  unit_price decimal(14,2) NOT NULL DEFAULT 0,
  subject varchar(190) NOT NULL DEFAULT '',
  currency char(3) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'upcoming',
  renewed_on date DEFAULT NULL,
  invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
  notes text,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY client_id (client_id),
  KEY client_service_id (client_service_id),
  KEY status_due (status,due_date)
) $charset;";

		$sql[] = "CREATE TABLE {$t['charges']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_service_id bigint(20) unsigned NOT NULL DEFAULT 0,
  service_id bigint(20) unsigned NOT NULL DEFAULT 0,
  renewal_id bigint(20) unsigned NOT NULL DEFAULT 0,
  description varchar(255) NOT NULL DEFAULT '',
  cost decimal(14,2) NOT NULL DEFAULT 0,
  price decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  incurred_on date DEFAULT NULL,
  billing_timing varchar(40) NOT NULL DEFAULT 'now',
  bill_on_date date DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
  notes text,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY client_id (client_id),
  KEY status_bill (status,bill_on_date)
) $charset;";

		$sql[] = "CREATE TABLE {$t['invoices']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  invoice_number varchar(60) NOT NULL DEFAULT '',
  title varchar(190) NOT NULL DEFAULT '',
  description text,
  amount decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  issue_date date DEFAULT NULL,
  due_date date DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  invoice_url varchar(500) NOT NULL DEFAULT '',
  attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  amount_paid decimal(14,2) NOT NULL DEFAULT 0,
  subtotal decimal(14,2) NOT NULL DEFAULT 0,
  discount_type varchar(10) NOT NULL DEFAULT 'none',
  discount_value decimal(14,2) NOT NULL DEFAULT 0,
  discount_amount decimal(14,2) NOT NULL DEFAULT 0,
  tax_rate decimal(6,2) NOT NULL DEFAULT 0,
  tax_amount decimal(14,2) NOT NULL DEFAULT 0,
  terms text,
  public_token varchar(64) NOT NULL DEFAULT '',
  sent_at datetime DEFAULT NULL,
  viewed_at datetime DEFAULT NULL,
  view_count int(11) NOT NULL DEFAULT 0,
  notes text,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY client_id (client_id),
  KEY status_due (status,due_date),
  KEY invoice_number (invoice_number),
  KEY public_token (public_token)
) $charset;";

		$sql[] = "CREATE TABLE {$t['invoice_items']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source_type varchar(20) NOT NULL DEFAULT 'manual',
  source_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_service_id bigint(20) unsigned NOT NULL DEFAULT 0,
  description varchar(255) NOT NULL DEFAULT '',
  period_start date DEFAULT NULL,
  period_end date DEFAULT NULL,
  kind varchar(10) NOT NULL DEFAULT 'fixed',
  service_id bigint(20) unsigned NOT NULL DEFAULT 0,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  cost decimal(14,2) NOT NULL DEFAULT 0,
  quantity decimal(10,2) NOT NULL DEFAULT 1,
  unit_price decimal(14,2) NOT NULL DEFAULT 0,
  amount decimal(14,2) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY invoice_id (invoice_id),
  KEY client_service_id (client_service_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['invoice_installments']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
  label varchar(190) NOT NULL DEFAULT '',
  due_date date DEFAULT NULL,
  percent decimal(6,2) DEFAULT NULL,
  amount decimal(14,2) NOT NULL DEFAULT 0,
  sort_order int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY invoice_id (invoice_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['client_contacts']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  phone varchar(60) NOT NULL DEFAULT '',
  job_title varchar(190) NOT NULL DEFAULT '',
  kind varchar(20) NOT NULL DEFAULT 'primary',
  sort_order int(11) NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY client_id (client_id),
  KEY email (email)
) $charset;";

		$sql[] = "CREATE TABLE {$t['team_members']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  role varchar(190) NOT NULL DEFAULT '',
  cc_invoices tinyint(1) NOT NULL DEFAULT 1,
  wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(12) NOT NULL DEFAULT 'employee',
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  salary_amount decimal(14,2) NOT NULL DEFAULT 0,
  salary_period varchar(20) NOT NULL DEFAULT 'monthly',
  currency char(3) NOT NULL DEFAULT '',
  start_date date DEFAULT NULL,
  end_date date DEFAULT NULL,
  bank_name varchar(120) NOT NULL DEFAULT '',
  account_name varchar(190) NOT NULL DEFAULT '',
  account_number varchar(60) NOT NULL DEFAULT '',
  notes text,
  status varchar(20) NOT NULL DEFAULT 'active',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) $charset;";

		$sql[] = "CREATE TABLE {$t['email_log']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(20) NOT NULL DEFAULT 'invoice',
  to_emails text,
  cc_emails text,
  bcc_emails text,
  subject varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'sent',
  error text,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY invoice_id (invoice_id),
  KEY client_id (client_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['projects']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(190) NOT NULL DEFAULT '',
  description text,
  status varchar(20) NOT NULL DEFAULT 'active',
  start_date date DEFAULT NULL,
  due_date date DEFAULT NULL,
  owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  price decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY client_id (client_id),
  KEY status (status)
) $charset;";

		$sql[] = "CREATE TABLE {$t['tasks']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  project_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(255) NOT NULL DEFAULT '',
  notes text,
  status varchar(20) NOT NULL DEFAULT 'todo',
  priority varchar(10) NOT NULL DEFAULT 'normal',
  due_date date DEFAULT NULL,
  assignee_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_service_id bigint(20) unsigned NOT NULL DEFAULT 0,
  invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
  phase_id bigint(20) unsigned NOT NULL DEFAULT 0,
  pay_amount decimal(14,2) NOT NULL DEFAULT 0,
  completed_at datetime DEFAULT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY project_id (project_id),
  KEY client_id (client_id),
  KEY assignee_id (assignee_id),
  KEY status_due (status,due_date)
) $charset;";

		$sql[] = "CREATE TABLE {$t['project_phases']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  project_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(190) NOT NULL DEFAULT '',
  sort_order int(11) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'todo',
  start_date date DEFAULT NULL,
  due_date date DEFAULT NULL,
  price decimal(14,2) NOT NULL DEFAULT 0,
  team_budget decimal(14,2) NOT NULL DEFAULT 0,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  completed_at datetime DEFAULT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY project_id (project_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['pay_allocations']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scope varchar(10) NOT NULL DEFAULT 'phase',
  scope_id bigint(20) unsigned NOT NULL DEFAULT 0,
  team_member_id bigint(20) unsigned NOT NULL DEFAULT 0,
  mode varchar(10) NOT NULL DEFAULT 'fixed',
  value decimal(14,2) NOT NULL DEFAULT 0,
  note varchar(255) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY scope (scope,scope_id),
  KEY team_member_id (team_member_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['pay_items']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  team_member_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(16) NOT NULL DEFAULT 'bonus',
  project_id bigint(20) unsigned NOT NULL DEFAULT 0,
  phase_id bigint(20) unsigned NOT NULL DEFAULT 0,
  task_id bigint(20) unsigned NOT NULL DEFAULT 0,
  allocation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  period varchar(7) NOT NULL DEFAULT '',
  description varchar(255) NOT NULL DEFAULT '',
  amount decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  earned_on date DEFAULT NULL,
  status varchar(12) NOT NULL DEFAULT 'payable',
  payout_id bigint(20) unsigned NOT NULL DEFAULT 0,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY team_member_id (team_member_id),
  KEY status (status),
  KEY phase_id (phase_id),
  KEY payout_id (payout_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['payouts']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  number varchar(30) NOT NULL DEFAULT '',
  team_member_id bigint(20) unsigned NOT NULL DEFAULT 0,
  paid_on date DEFAULT NULL,
  method varchar(60) NOT NULL DEFAULT '',
  reference varchar(120) NOT NULL DEFAULT '',
  amount decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  notes text,
  status varchar(12) NOT NULL DEFAULT 'paid',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY team_member_id (team_member_id),
  KEY paid_on (paid_on)
) $charset;";

		$sql[] = "CREATE TABLE {$t['contract_templates']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  description varchar(255) NOT NULL DEFAULT '',
  body longtext,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['contracts']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  number varchar(30) NOT NULL DEFAULT '',
  title varchar(190) NOT NULL DEFAULT '',
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  template_id bigint(20) unsigned NOT NULL DEFAULT 0,
  project_id bigint(20) unsigned NOT NULL DEFAULT 0,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'draft',
  body longtext,
  body_hash char(64) NOT NULL DEFAULT '',
  final_hash char(64) NOT NULL DEFAULT '',
  pdf_file varchar(190) NOT NULL DEFAULT '',
  pdf_hash char(64) NOT NULL DEFAULT '',
  pdf_size int(11) NOT NULL DEFAULT 0,
  pdf_at datetime DEFAULT NULL,
  preview_slug varchar(64) NOT NULL DEFAULT '',
  view_access varchar(10) NOT NULL DEFAULT 'private',
  sent_at datetime DEFAULT NULL,
  completed_at datetime DEFAULT NULL,
  expires_on date DEFAULT NULL,
  notes text,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY client_id (client_id),
  KEY status (status),
  KEY preview_slug (preview_slug)
) $charset;";

		$sql[] = "CREATE TABLE {$t['contract_signers']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  contract_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(190) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  role varchar(20) NOT NULL DEFAULT 'client',
  sort_order int(11) NOT NULL DEFAULT 0,
  token varchar(64) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'pending',
  invited_at datetime DEFAULT NULL,
  viewed_at datetime DEFAULT NULL,
  consent_at datetime DEFAULT NULL,
  otp_verified_at datetime DEFAULT NULL,
  signed_at datetime DEFAULT NULL,
  sign_name varchar(190) NOT NULL DEFAULT '',
  sign_method varchar(12) NOT NULL DEFAULT '',
  sign_image mediumtext,
  sign_ip varchar(64) NOT NULL DEFAULT '',
  sign_forwarded varchar(255) NOT NULL DEFAULT '',
  sign_ua varchar(500) NOT NULL DEFAULT '',
  sign_env text,
  declined_reason text,
  PRIMARY KEY  (id),
  KEY contract_id (contract_id),
  KEY token (token)
) $charset;";

		$sql[] = "CREATE TABLE {$t['contract_events']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  contract_id bigint(20) unsigned NOT NULL DEFAULT 0,
  signer_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  event varchar(40) NOT NULL DEFAULT '',
  detail text,
  ip varchar(64) NOT NULL DEFAULT '',
  ua varchar(500) NOT NULL DEFAULT '',
  created_at datetime DEFAULT NULL,
  prev_hash char(64) NOT NULL DEFAULT '',
  hash char(64) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY contract_id (contract_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['contract_otps']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  contract_id bigint(20) unsigned NOT NULL DEFAULT 0,
  signer_id bigint(20) unsigned NOT NULL DEFAULT 0,
  email varchar(190) NOT NULL DEFAULT '',
  code varchar(10) NOT NULL DEFAULT '',
  sent_at datetime DEFAULT NULL,
  expires_at datetime DEFAULT NULL,
  mail_ok tinyint(1) NOT NULL DEFAULT 0,
  mail_error varchar(255) NOT NULL DEFAULT '',
  attempts int(11) NOT NULL DEFAULT 0,
  entered_code varchar(10) NOT NULL DEFAULT '',
  entered_at datetime DEFAULT NULL,
  status varchar(12) NOT NULL DEFAULT 'sent',
  purpose varchar(8) NOT NULL DEFAULT 'sign',
  ip varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY signer_id (signer_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['expenses']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  spent_on date DEFAULT NULL,
  amount decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  category varchar(40) NOT NULL DEFAULT '',
  vendor varchar(190) NOT NULL DEFAULT '',
  description varchar(255) NOT NULL DEFAULT '',
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  project_id bigint(20) unsigned NOT NULL DEFAULT 0,
  method varchar(60) NOT NULL DEFAULT '',
  reference varchar(120) NOT NULL DEFAULT '',
  attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  recurring_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(12) NOT NULL DEFAULT 'paid',
  notes text,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY spent_on (spent_on),
  KEY category (category),
  KEY department_id (department_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['recurring_expenses']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  vendor varchar(190) NOT NULL DEFAULT '',
  category varchar(40) NOT NULL DEFAULT '',
  amount decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  period varchar(40) NOT NULL DEFAULT 'monthly',
  next_date date DEFAULT NULL,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(12) NOT NULL DEFAULT 'active',
  notes text,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY next_date (next_date)
) $charset;";

		$sql[] = "CREATE TABLE {$t['payments']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  amount decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  paid_on date DEFAULT NULL,
  reference varchar(190) NOT NULL DEFAULT '',
  method varchar(40) NOT NULL DEFAULT '',
  notes text,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  updated_at datetime DEFAULT NULL,
  department_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY invoice_id (invoice_id),
  KEY client_id (client_id)
) $charset;";

		$sql[] = "CREATE TABLE {$t['activity']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entity_type varchar(40) NOT NULL DEFAULT '',
  entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
  client_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(40) NOT NULL DEFAULT '',
  summary varchar(500) NOT NULL DEFAULT '',
  meta longtext,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY entity (entity_type,entity_id),
  KEY client_created (client_id,created_at)
) $charset;";

		$sql[] = "CREATE TABLE {$t['lookups']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(40) NOT NULL DEFAULT '',
  item_key varchar(60) NOT NULL DEFAULT '',
  label varchar(190) NOT NULL DEFAULT '',
  color varchar(20) NOT NULL DEFAULT '',
  months int(11) NOT NULL DEFAULT 0,
  sort_order int(11) NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  is_system tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY type_key (type,item_key)
) $charset;";

		foreach ( $sql as $s ) {
			dbDelta( $s );
		}
	}
}
