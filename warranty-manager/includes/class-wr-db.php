<?php
defined( 'ABSPATH' ) || exit;

/**
 * Table names and schema. Customer personal data columns hold ciphertext (see WR_Crypto).
 */
class WR_DB {

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'wr_' . $name;
	}

	public static function schema(): string {
		global $wpdb;
		$c = $wpdb->get_charset_collate();

		$products = self::table( 'products' );
		$serials  = self::table( 'serials' );
		$batches  = self::table( 'batches' );
		$warr     = self::table( 'warranties' );
		$terms    = self::table( 'terms' );
		$consents = self::table( 'consents' );
		$rules    = self::table( 'rules' );
		$notlog   = self::table( 'notification_log' );
		$audit    = self::table( 'audit_log' );

		return "CREATE TABLE $products (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(64) NOT NULL,
  name varchar(191) NOT NULL DEFAULT '',
  duration_months smallint(5) unsigned NOT NULL DEFAULT 24,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code)
) $c;
CREATE TABLE $serials (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  serial varchar(64) NOT NULL,
  serial_key varchar(64) NOT NULL,
  product_id bigint(20) unsigned DEFAULT NULL,
  sale_date date DEFAULT NULL,
  batch_id bigint(20) unsigned DEFAULT NULL,
  status varchar(16) NOT NULL DEFAULT 'available',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY serial_key (serial_key),
  KEY batch_id (batch_id),
  KEY status (status),
  KEY product_id (product_id)
) $c;
CREATE TABLE $batches (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  filename varchar(191) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL DEFAULT 'pending',
  auto_products tinyint(1) NOT NULL DEFAULT 1,
  total_rows int(10) unsigned NOT NULL DEFAULT 0,
  processed int(10) unsigned NOT NULL DEFAULT 0,
  inserted int(10) unsigned NOT NULL DEFAULT 0,
  duplicates int(10) unsigned NOT NULL DEFAULT 0,
  invalid int(10) unsigned NOT NULL DEFAULT 0,
  file_pos bigint(20) unsigned NOT NULL DEFAULT 0,
  errors longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id)
) $c;
CREATE TABLE $warr (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(32) NOT NULL,
  serial_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned DEFAULT NULL,
  full_name text NULL,
  email text NULL,
  phone text NULL,
  email_idx char(64) NOT NULL DEFAULT '',
  phone_idx char(64) NOT NULL DEFAULT '',
  name_idx varchar(255) NOT NULL DEFAULT '',
  starts_on date NOT NULL,
  expires_on date NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'active',
  source varchar(16) NOT NULL DEFAULT 'web',
  terms_id bigint(20) unsigned DEFAULT NULL,
  notes text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  anonymized_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  UNIQUE KEY serial_id (serial_id),
  KEY email_idx (email_idx),
  KEY phone_idx (phone_idx),
  KEY expires_on (expires_on),
  KEY status (status)
) $c;
CREATE TABLE $terms (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  version varchar(32) NOT NULL,
  content longtext NOT NULL,
  content_hash char(64) NOT NULL,
  is_current tinyint(1) NOT NULL DEFAULT 0,
  published_by bigint(20) unsigned NOT NULL DEFAULT 0,
  published_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY is_current (is_current)
) $c;
CREATE TABLE $consents (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  warranty_id bigint(20) unsigned NOT NULL,
  consent_type varchar(32) NOT NULL,
  terms_id bigint(20) unsigned DEFAULT NULL,
  terms_version varchar(32) NOT NULL DEFAULT '',
  terms_hash char(64) NOT NULL DEFAULT '',
  accepted_at datetime NOT NULL,
  ip_address text NULL,
  user_agent text NULL,
  recorded_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY warranty_id (warranty_id)
) $c;
CREATE TABLE $rules (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  days_before smallint(5) unsigned NOT NULL,
  channel varchar(8) NOT NULL,
  recipient varchar(16) NOT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id)
) $c;
CREATE TABLE $notlog (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  warranty_id bigint(20) unsigned DEFAULT NULL,
  rule_id bigint(20) unsigned DEFAULT NULL,
  kind varchar(24) NOT NULL,
  channel varchar(8) NOT NULL,
  recipient text NULL,
  status varchar(16) NOT NULL,
  error text NULL,
  sent_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY warranty_rule (warranty_id,rule_id),
  KEY sent_at (sent_at)
) $c;
CREATE TABLE $audit (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(64) NOT NULL,
  object_type varchar(32) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned DEFAULT NULL,
  details text NULL,
  ip_address varchar(45) DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY object (object_type,object_id),
  KEY created_at (created_at)
) $c;";
	}
}
