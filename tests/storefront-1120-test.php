<?php
/**
 * 1.12.0 storefront (lane WOO-STOREFRONT): the seller-designed quote form, quick
 * order, "Add to Quote" on block product grids, the Cart block control and the
 * Checkout block's net-terms re-check.
 *
 * Every guard below has a mutant that turns it red (recorded in the PR):
 *
 *   QF1  the seller form is used only with a versionId and both buyer roles, and
 *        not when a required file field cannot be answered here.
 *   QF2  answers are checked against the version the shopper saw; a mismatch is an
 *        error that drops the cached definition.
 *   QF3  a file answer names only an upload this request carries.
 *   QF4  the role answers become the request's buyer fields.
 *   QF5  the form is read only when the server lists `quote-form`.
 *   QO1  quick order: a variable product's own SKU is `needs_options`, never added.
 *   QO2  a hidden or draft product is `not_found`.
 *   QO3  add to cart applies `woocommerce_add_to_cart_validation`, and a variation
 *        must belong to the product named.
 *   QO4  input is bounded (50 lines, 64-char SKU, quantity 1..100000).
 *   CB1  the block-grid card control: off by default, never inside the Add to Cart
 *        with Options form, and one control per card on a block archive.
 *   CT1  the Cart block integration registers only with the cart setting on, and
 *        the footer control stands down when it is active.
 *   NT1  the Checkout block label falls back to the gateway's default title.
 *   NT2  the Store API cart carries the gateway's own decision for THIS cart.
 *
 * Included by tests/run.php. Uses check() from there and the doubles of
 * storefront-parity-test.php.
 *
 * @package TackQuotes
 */

defined( 'ABSPATH' ) || exit;

// ── Stubs ────────────────────────────────────────────────────────────────────
$GLOBALS['TACK_SKUS']        = array();
$GLOBALS['TACK_WC_NOTICES']  = array();
$GLOBALS['TACK_DID_ACTIONS'] = array();
if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
	/** @param string $sku SKU. @return int */
	function wc_get_product_id_by_sku( $sku ) {
		return isset( $GLOBALS['TACK_SKUS'][ $sku ] ) ? (int) $GLOBALS['TACK_SKUS'][ $sku ] : 0;
	}
}
if ( ! function_exists( 'wc_get_notices' ) ) {
	/** @param string $type Type. @return array */
	function wc_get_notices( $type = '' ) {
		return '' === $type ? $GLOBALS['TACK_WC_NOTICES'] : ( isset( $GLOBALS['TACK_WC_NOTICES'][ $type ] ) ? $GLOBALS['TACK_WC_NOTICES'][ $type ] : array() );
	}
}
if ( ! function_exists( 'wc_clear_notices' ) ) {
	/** Clear. */
	function wc_clear_notices() {
		$GLOBALS['TACK_WC_NOTICES'] = array();
	}
}
if ( ! function_exists( 'wc_set_notices' ) ) {
	/** @param array $n Notices. */
	function wc_set_notices( $n ) {
		$GLOBALS['TACK_WC_NOTICES'] = $n;
	}
}
if ( ! function_exists( 'wc_get_product_variation_attributes' ) ) {
	/** @param int $id Variation. @return array */
	function wc_get_product_variation_attributes( $id ) {
		return array( 'attribute_pa_size' => 'large' );
	}
}
if ( ! function_exists( 'determine_locale' ) ) {
	/** @return string */
	function determine_locale() {
		return 'de_DE';
	}
}
if ( ! function_exists( 'did_action' ) ) {
	/** @param string $hook Hook. @return int */
	function did_action( $hook ) {
		return in_array( $hook, $GLOBALS['TACK_DID_ACTIONS'], true ) ? 1 : 0;
	}
}

/** Adds a WooCommerce error notice the way wc_add_notice( $m, 'error' ) stores it. */
function tack_1120_error( $message ) {
	$GLOBALS['TACK_WC_NOTICES']['error'][] = array( 'notice' => $message );
}

/** A product with a price HTML, for quick order. */
class Tack_1120_Product extends Tack_Parity_Product {
	/** @var string */
	public $status = 'publish';
	/** @return string */
	public function get_price_html() {
		return '<span class="amount">' . number_format( (float) $this->price, 2 ) . '</span>';
	}
	/** @return string */
	public function get_status() {
		return $this->status;
	}
	/** @var bool WooCommerce catalog visibility (Hidden = false). */
	public $visible = true;
	/** @return bool */
	public function is_visible() {
		return $this->visible;
	}
}

/** A variation double (instanceof WC_Product_Variation, defined by standards-audit-test.php). */
class Tack_1120_Variation extends WC_Product_Variation {
	/** @var string */
	public $sku = '';
	/** @var string */
	public $name = '';
	/** @var float */
	public $price = 0.0;
	/** @return string */
	public function get_name() {
		return $this->name;
	}
	/** @return string */
	public function get_sku() {
		return $this->sku;
	}
	/** @param string $t Type. @return bool */
	public function is_type( $t ) {
		return 'variation' === $t;
	}
	/** @return bool */
	public function is_purchasable() {
		return (bool) apply_filters( 'woocommerce_is_purchasable', true, $this );
	}
	/** @return bool */
	public function is_in_stock() {
		return true;
	}
	/** @return string */
	public function get_price_html() {
		return '<span class="amount">' . number_format( (float) $this->price, 2 ) . '</span>';
	}
}

/** The cart a quick order adds to. */
class Tack_1120_Cart {
	/** @var array */
	public $added = array();
	/** @var bool */
	public $checked = false;
	/** @var bool Refuse every add, as add_to_cart() does for a non-purchasable product. */
	public $refuse = false;
	/**
	 * @param int   $product_id   Product.
	 * @param int   $quantity     Quantity.
	 * @param int   $variation_id Variation.
	 * @param array $variation    Attributes.
	 * @return string|false
	 */
	public function add_to_cart( $product_id, $quantity, $variation_id, $variation ) {
		if ( $this->refuse ) {
			tack_1120_error( 'Sorry, this product cannot be purchased.' );
			return false;
		}
		$this->added[] = array( $product_id, $quantity, $variation_id, $variation );
		return 'key' . count( $this->added );
	}
	/** Order limits run here. */
	public function check_cart_items() {
		$this->checked = true;
		tack_1120_error( 'Minimum order is 10.' );
	}
}

/** Quick order with an injected cart. */
class Tack_1120_Quick_Order extends Tack_Quick_Order {
	/** @var Tack_1120_Cart */
	public $test_cart;
	/** @return object */
	protected function cart() {
		return $this->test_cart;
	}
}

/** A client whose capability list and quote-form answer are scripted. */
class Tack_1120_Client extends Tack_Api_Client {
	/** @var string[] */
	public $caps = array();
	/** @var mixed */
	public $form;
	/** @var string[] */
	public $asked = array();
	/** @param bool $refresh Ignored. @return string[] */
	public function server_capabilities( $refresh = false ) {
		return $this->caps;
	}
	/** @param string $locale Locale. @return mixed */
	public function get_quote_form( $locale ) {
		$this->asked[] = $locale;
		return $this->form;
	}
}

/** The seller form TackQuote serves in these tests. */
function tack_1120_form( $extra = array() ) {
	return array(
		'id'        => 'f1',
		'slug'      => 'rfq',
		'versionId' => '7d1e2f3a-4b5c-4d6e-8f70-1a2b3c4d5e6f',
		'fields'    => array_merge(
			array(
				array( 'key' => 'name', 'label' => 'Your name', 'type' => 'text', 'required' => true, 'role' => 'buyer_name' ),
				array( 'key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'role' => 'buyer_email' ),
				array( 'key' => 'org', 'label' => 'Company', 'type' => 'text', 'role' => 'buyer_company' ),
				array( 'key' => 'msg', 'label' => 'Message', 'type' => 'textarea', 'role' => 'buyer_message' ),
				array( 'key' => 'trade', 'label' => 'Trade buyer', 'type' => 'checkbox' ),
				array( 'key' => 'licence', 'label' => 'Licence', 'type' => 'text', 'required' => true, 'showIf' => array( 'field' => 'trade', 'equals' => true ) ),
				array( 'key' => 'by', 'label' => 'Needed by', 'type' => 'date', 'min' => 'today' ),
			),
			$extra
		),
		'calendar'  => array( 'timeZone' => 'Asia/Kolkata', 'today' => '2026-10-17' ),
	);
}

/**
 * A quote-form reader with a scripted client.
 *
 * @param mixed     $form    The quote-form answer.
 * @param string[]  $caps    Server capabilities.
 * @param bool|null $uploads Whether files can be attached.
 * @return array{0:Tack_Quote_Form,1:Tack_1120_Client}
 */
function tack_1120_reader( $form, $caps = array( 'attachments', 'quote-form' ), $uploads = true ) {
	Tack_Quote_Form::reset_request_cache();
	$GLOBALS['TACK_TRANSIENTS'] = array();
	tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );
	$client       = new Tack_1120_Client();
	$client->caps = $caps;
	$client->form = $form;
	return array( new Tack_Quote_Form( $client, new Tack_Storefront_Forms( $client ), $uploads ), $client );
}

$UPLOAD_A = '3f2b8c1e-9d4a-4b7e-8c21-5a6d7e8f9a0b';
$UPLOAD_B = '4a3c9d2f-0e5b-4c8f-9d32-6b7e8f9a0b1c';

// ── QF5 / QF1: when the seller form is used ─────────────────────────────────
tack_parity_reset();
list( $qf, $qc ) = tack_1120_reader( tack_1120_form() );
check( 'QF1: a designed form (versionId + both buyer roles) is used', null !== $qf->definition() );
check( 'QF1: it is read in the visitor\'s locale as BCP 47 (de-DE)', array( 'de-DE' ) === $qc->asked, implode( ',', $qc->asked ) );
check( 'QF1: script config carries the version, the locale shown, the template id and role => key', array( 'versionId', 'locale', 'templateId', 'roles', 'maxFiles' ) === array_keys( (array) $qf->script_config() ) && 'de-DE' === $qf->script_config()['locale'] );

// P5: no quote control can open the modal here -> no read at all.
list( $qf, $qc ) = tack_1120_reader( tack_1120_form() );
tack_test_set_option( Tack_Widget::OPT_FAB_PAGES, 'none' );
check( 'P5: a page where nothing opens the modal reads no form (script config null, route never asked)', null === $qf->script_config() && array() === $qc->asked );
tack_test_set_option( Tack_Widget::OPT_FAB_PAGES, 'all' );

// P5: soft TTL - a stale copy is served while ONE request refreshes it.
list( $qf, $qc ) = tack_1120_reader( tack_1120_form() );
$stale_form              = tack_1120_form();
$stale_form['versionId'] = '11111111-1111-4111-8111-111111111111';
$GLOBALS['TACK_TRANSIENTS'][ Tack_Quote_Form::CACHE_TRANSIENT ] = array( 'de-DE' => array( 'until' => time() - 5, 'stale' => time() + 600, 'form' => $stale_form ) );
$GLOBALS['TACK_TRANSIENTS'][ Tack_Quote_Form::REFRESH_LOCK . md5( 'de-DE' ) ] = 1;
$got = $qf->definition();
check( 'P5: past the TTL, while another request holds the refresh lock, the stale copy is served without a read', is_array( $got ) && $stale_form['versionId'] === $got['versionId'] && array() === $qc->asked );
unset( $GLOBALS['TACK_TRANSIENTS'][ Tack_Quote_Form::REFRESH_LOCK . md5( 'de-DE' ) ] );
Tack_Quote_Form::reset_request_cache();
$got = $qf->definition();
check( 'P5: the request that takes the lock refreshes, and gets the new form', 1 === count( $qc->asked ) && is_array( $got ) && tack_1120_form()['versionId'] === $got['versionId'] && ! empty( $GLOBALS['TACK_TRANSIENTS'][ Tack_Quote_Form::REFRESH_LOCK . md5( 'de-DE' ) ] ) );
$GLOBALS['TACK_TRANSIENTS'][ Tack_Quote_Form::CACHE_TRANSIENT ] = array( 'de-DE' => array( 'until' => time() - 5, 'stale' => time() + 600, 'form' => $stale_form ) );
Tack_Quote_Form::reset_request_cache();
$qc->form = new WP_Error( 'tack_http_503', 'down', array( 'status' => 503 ) );
$got      = $qf->definition();
check( 'P5: a failed refresh keeps the stale copy (no form switch mid-session)', is_array( $got ) && $stale_form['versionId'] === $got['versionId'] );

// P6: answers are collected against the form in the locale it was shown in.
list( $qf, $qc ) = tack_1120_reader( tack_1120_form() );
$qf->collect( array(), array(), 'x', array(), 'fr-FR' );
check( 'P6: the posted locale picks the form the answers are checked against', array( 'fr-FR' ) === $qc->asked );
Tack_Quote_Form::reset_request_cache();
$qf->collect( array(), array(), 'x', array(), '../../etc' );
check( 'P6: a posted locale that is not a BCP 47 tag is read as en', in_array( 'en', $qc->asked, true ) );

// P1: more REQUIRED file fields than one request can carry -> built-in form.
$four = array();
for ( $f = 1; $f <= 4; $f++ ) {
	$four[] = array( 'key' => 'f' . $f, 'label' => 'F' . $f, 'type' => 'file', 'required' => true );
}
list( $qf ) = tack_1120_reader( tack_1120_form( $four ) );
check( 'P1: four required file fields (one request carries 3 files) -> built-in form', null === $qf->definition() );
list( $qf ) = tack_1120_reader( tack_1120_form( array_slice( $four, 0, 3 ) ) );
check( 'P1: three required file fields fit', null !== $qf->definition() );

list( $qf, $qc ) = tack_1120_reader( tack_1120_form(), array( 'attachments' ) );
check( 'QF5: no `quote-form` capability -> built-in form, and the route is never asked', null === $qf->definition() && array() === $qc->asked );

$default              = tack_1120_form();
$default['versionId'] = null;
list( $qf ) = tack_1120_reader( $default );
check( 'QF1: versionId null (TackQuote\'s built-in default) -> built-in form', null === $qf->definition() );

$no_email           = tack_1120_form();
$no_email['fields'] = array_values(
	array_filter(
		$no_email['fields'],
		function ( $f ) {
			return 'email' !== $f['key'];
		}
	)
);
list( $qf ) = tack_1120_reader( $no_email );
check( 'QF1: a definition without a buyer_email field is not used', null === $qf->definition() );

$with_file = tack_1120_form( array( array( 'key' => 'drawing', 'label' => 'Drawing', 'type' => 'file', 'required' => true, 'maxFiles' => 2 ) ) );
list( $qf ) = tack_1120_reader( $with_file, array( 'attachments', 'quote-form' ), false );
check( 'QF1: a REQUIRED file field with no way to attach here -> built-in form', null === $qf->definition() );
list( $qf ) = tack_1120_reader( $with_file, array( 'attachments', 'quote-form' ), true );
check( 'QF1: the same form is used when files can be attached', null !== $qf->definition() );

list( $qf, $qc ) = tack_1120_reader( new WP_Error( 'tack_http_500', 'down', array( 'status' => 500 ) ) );
check( 'QF1: a failed read -> built-in form', null === $qf->definition() );
Tack_Quote_Form::reset_request_cache();
$qf->definition();
check( 'QF1: a failed read is remembered (no second request within the minute)', 1 === count( $qc->asked ) );

// ── Rendering ────────────────────────────────────────────────────────────────
list( $qf ) = tack_1120_reader( $with_file );
$html = $qf->fields_html( $with_file );
check( 'render: fields post as tq_answers[key]', false !== strpos( $html, 'name="tq_answers[email]"' ) && false !== strpos( $html, 'name="tq_answers[msg]"' ) );
check( 'render: ids carry the quote prefix, never the application forms\' tack_sf_', false !== strpos( $html, 'id="tack_qf_email"' ) && false === strpos( $html, 'id="tack_sf_' ) );
check( 'render: the conditional field carries its condition', false !== strpos( $html, 'data-tack-show-if-field="trade"' ) );
check( 'render: the seller\'s today is the earliest date', false !== strpos( $html, 'data-tack-seller-today="' ) );
check( 'render: a 2-file field is a multiple input posting to tq_files[key]', false !== strpos( $html, 'name="tq_files[drawing]"' ) && false !== strpos( $html, 'multiple="multiple" data-tack-max-files="2"' ) );
$app_html = ( new Tack_Storefront_Forms() )->render_field( array( 'key' => 'cert', 'label' => 'Cert', 'type' => 'file', 'maxFiles' => 3 ), 'tack_sf', null, 'upload' );
check( 'render: an application file field stays single (tack_sf_files, no multiple)', false !== strpos( $app_html, 'name="tack_sf_files[cert]"' ) && false === strpos( $app_html, 'multiple' ) );
ob_start();
$qf->render_template();
$tpl = (string) ob_get_clean();
check( 'render: the footer template carries the version', 0 === strpos( $tpl, '<template id="tack-quote-seller-form" data-tack-version="7d1e2f3a-4b5c-4d6e-8f70-1a2b3c4d5e6f">' ) );
list( $qf ) = tack_1120_reader( $default );
ob_start();
$qf->render_template();
check( 'render: no template for the built-in form', '' === (string) ob_get_clean() );

// ── QF2 / QF3 / QF4: collecting answers ─────────────────────────────────────
list( $qf ) = tack_1120_reader( $with_file );
$version = $with_file['versionId'];
$base    = array(
	'name'  => 'Ada  Byron Lovelace',
	'email' => 'ada@example.com',
	'org'   => 'Analytical Ltd',
	'msg'   => "Line one\nLine two",
);
$out = $qf->collect( $base, array( 'drawing' => array( $UPLOAD_A ) ), $version, array( $UPLOAD_A ) );
check( 'QF4: the role answers become the buyer fields', ! is_wp_error( $out ) && array(
	'email'     => 'ada@example.com',
	'firstName' => 'Ada',
	'lastName'  => 'Byron Lovelace',
	'company'   => 'Analytical Ltd',
	'phone'     => '',
	'message'   => "Line one\nLine two",
) === $out['buyer'], is_wp_error( $out ) ? $out->get_error_message() : wp_json_encode( $out['buyer'] ) );
check( 'QF2: the version the shopper saw travels as formVersionId', ! is_wp_error( $out ) && $version === $out['formVersionId'] );
check( 'QF3: a multi-file field answers an array of {uploadId}', ! is_wp_error( $out ) && array( array( 'uploadId' => $UPLOAD_A ) ) === $out['answers']['drawing'] );
check( 'collect: an unticked checkbox answers false; its hidden required field is not demanded', ! is_wp_error( $out ) && false === $out['answers']['trade'] && ! isset( $out['answers']['licence'] ) );

$GLOBALS['TACK_TRANSIENTS'][ Tack_Quote_Form::CACHE_TRANSIENT ] = array( 'de-DE' => array( 'until' => time() + 300, 'form' => $with_file ) );
$stale = $qf->collect( $base, array(), 'f0000000-0000-4000-8000-000000000000', array() );
check( 'QF2: another version is refused with reload', is_wp_error( $stale ) && 'tack_form_changed' === $stale->get_error_code() && ! empty( $stale->get_error_data()['reload'] ) );
check( 'QF2: and the cached definition is dropped', ! isset( $GLOBALS['TACK_TRANSIENTS'][ Tack_Quote_Form::CACHE_TRANSIENT ] ) );

list( $qf ) = tack_1120_reader( $with_file );
$forged = $qf->collect( $base, array( 'drawing' => array( $UPLOAD_B ) ), $version, array( $UPLOAD_A ) );
check( 'QF3: a file id this request did not upload is never an answer (required -> error)', is_wp_error( $forged ) && 'tack_form_invalid' === $forged->get_error_code() );

$missing = $qf->collect( array( 'name' => 'Ada', 'email' => '' ), array( 'drawing' => array( $UPLOAD_A ) ), $version, array( $UPLOAD_A ) );
check( 'collect: a required field left blank is refused before any request', is_wp_error( $missing ) && false !== strpos( $missing->get_error_message(), 'Email' ) );

$ticked = $qf->collect( array_merge( $base, array( 'trade' => '1' ) ), array( 'drawing' => array( $UPLOAD_A ) ), $version, array( $UPLOAD_A ) );
check( 'collect: a shown conditional required field is demanded', is_wp_error( $ticked ) && false !== strpos( $ticked->get_error_message(), 'Licence' ) );

$too_many = $qf->collect( $base, array( 'drawing' => array( $UPLOAD_A, $UPLOAD_B, '5b4d0e3a-1f6c-4d9a-8e43-7c8f9a0b1c2d' ) ), $version, array( $UPLOAD_A, $UPLOAD_B, '5b4d0e3a-1f6c-4d9a-8e43-7c8f9a0b1c2d' ) );
check( 'QF3: more files than the field takes is refused', is_wp_error( $too_many ) );

check( 'QF2: a 409 from TackQuote reads as "form changed"', Tack_Quote_Form::refused_as_changed( new WP_Error( 'tack_http_409', 'changed', array( 'status' => 409 ) ) ) && ! Tack_Quote_Form::refused_as_changed( new WP_Error( 'tack_http_400', 'bad', array( 'status' => 400 ) ) ) );

// The handler: the answers ride on the existing request, gated on the posted version.
$src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-widget.php' );
check( 'handler: the seller form is read only when tq_version is posted', false !== strpos( $src, 'if ( isset( $_POST[ Tack_Quote_Form::VERSION_FIELD ] ) ) {' ) );
check( 'handler: answers + formVersionId join the payload only for the seller form', false !== strpos( $src, "if ( null !== \$form_answers ) {\n\t\t\t\$payload = array_merge( \$payload, \$form_answers );" ) );
check( 'handler: a 409 drops the cached form and asks for a reload', false !== strpos( $src, 'Tack_Quote_Form::refused_as_changed( $result )' ) && false !== strpos( $src, 'Tack_Quote_Form::forget();' ) );
check( 'client: the quote form is read with X-Api-Key only (v1 refuses a second credential)', 1 === preg_match( "/function get_quote_form.*?'Authorization' => null/s", (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-api-client.php' ) ) );

// ── Quick order ─────────────────────────────────────────────────────────────
tack_parity_reset();
$simple            = new Tack_1120_Product( 201, 'simple', 'Bolt M6', 'BOLT-6', 0.4 );
$variable          = new Tack_1120_Product( 202, 'variable', 'Glove', 'GLOVE', 5.0 );
$variation         = new Tack_1120_Variation( 203 );
$variation->parent_id = 202;
$variation->sku    = 'GLOVE-L';
$variation->name   = 'Glove - Large';
$variation->price  = 6.0;
$draft             = new Tack_1120_Product( 204, 'simple', 'Secret', 'SECRET', 1.0 );
$draft->status     = 'draft';
$GLOBALS['TACK_TEST_PRODUCTS'] = array(
	201 => $simple,
	202 => $variable,
	203 => $variation,
	204 => $draft,
);
$GLOBALS['TACK_SKUS'] = array(
	'BOLT-6'  => 201,
	'GLOVE'   => 202,
	'GLOVE-L' => 203,
	'SECRET'  => 204,
);
$qo  = new Tack_1120_Quick_Order();
$res = $qo->lookup(
	Tack_Quick_Order::parse_lines(
		wp_json_encode(
			array(
				array( 'sku' => 'BOLT-6', 'quantity' => 100 ),
				array( 'sku' => 'GLOVE', 'quantity' => 2 ),
				array( 'sku' => 'GLOVE-L', 'quantity' => 3 ),
				array( 'sku' => 'SECRET', 'quantity' => 1 ),
				array( 'sku' => 'NOPE', 'quantity' => 1 ),
			)
		)
	)
);
check( 'QO: a simple product SKU is found, with its id, price HTML and purchasable', 'found' === $res[0]['status'] && 201 === $res[0]['productId'] && 0 === $res[0]['variationId'] && 100 === $res[0]['quantity'] && $res[0]['purchasable'] && false !== strpos( $res[0]['priceHtml'], '0.40' ) );
check( 'QO1: a variable product\'s own SKU needs options (never orderable), with its page', 'needs_options' === $res[1]['status'] && ! isset( $res[1]['productId'] ) && '' !== $res[1]['url'] );
check( 'QO: a variation SKU is found as parent + variation', 'found' === $res[2]['status'] && 202 === $res[2]['productId'] && 203 === $res[2]['variationId'] );
$hidden_product          = new Tack_1120_Product( 205, 'simple', 'Catalog hidden', 'HIDDEN', 1.0 );
$hidden_product->visible = false;
$GLOBALS['TACK_TEST_PRODUCTS'][205] = $hidden_product;
$GLOBALS['TACK_SKUS']['HIDDEN']     = 205;
$hres = $qo->lookup( array( array( 'sku' => 'HIDDEN', 'quantity' => 1 ) ) );
check( 'P4: a product WooCommerce\'s catalog visibility hides is not_found', 'not_found' === $hres[0]['status'] );
check( 'QO2: a draft product is not_found (no name leaks)', 'not_found' === $res[3]['status'] && ! isset( $res[3]['name'] ) );
check( 'QO: an unknown SKU is not_found', 'not_found' === $res[4]['status'] );
tack_test_add_filter_return( 'woocommerce_product_is_visible', false );
$hidden = $qo->lookup( array( array( 'sku' => 'BOLT-6', 'quantity' => 1 ) ) );
tack_test_clear_filter_returns();
check( 'QO2: a product hidden from this visitor\'s group is not_found', 'not_found' === $hidden[0]['status'] );
tack_test_add_filter_return( 'woocommerce_is_purchasable', false );
$quote_only = $qo->lookup( array( array( 'sku' => 'BOLT-6', 'quantity' => 1 ) ) );
tack_test_clear_filter_returns();
check( 'QO: a quote-only product is found but not purchasable (quote list only)', 'found' === $quote_only[0]['status'] && false === $quote_only[0]['purchasable'] );

// QO4 bounds.
$many = array();
for ( $i = 0; $i < 60; $i++ ) {
	$many[] = array( 'sku' => 'S' . $i, 'quantity' => 1 );
}
$parsed = Tack_Quick_Order::parse_lines( wp_json_encode( array_merge( array( array( 'sku' => str_repeat( 'x', 65 ), 'quantity' => 1 ), array( 'sku' => '  ', 'quantity' => 2 ), array( 'sku' => 'Q', 'quantity' => -4 ), array( 'sku' => 'R', 'quantity' => 9999999 ) ), $many ) ) );
check( 'QO4: at most 50 lines', 50 === count( $parsed ) );
check( 'QO4: a 65-character SKU and a blank one are dropped', 'Q' === $parsed[0]['sku'] );
check( 'QO4: quantities are clamped to 1..100000', 1 === $parsed[0]['quantity'] && 100000 === $parsed[1]['quantity'] );
check( 'QO4: a non-JSON body is nothing', array() === Tack_Quick_Order::parse_lines( 'BOLT-6,3' ) );

// QO3 add to cart.
$cart            = new Tack_1120_Cart();
$qo->test_cart   = $cart;
$GLOBALS['TACK_WC_NOTICES'] = array( 'success' => array( array( 'notice' => 'earlier notice' ) ) );
$added = $qo->add_to_cart(
	array(
		array( 'product_id' => 201, 'variation_id' => 0, 'quantity' => 100 ),
		array( 'product_id' => 202, 'variation_id' => 203, 'quantity' => 3 ),
		array( 'product_id' => 201, 'variation_id' => 203, 'quantity' => 1 ),
		array( 'product_id' => 202, 'variation_id' => 0, 'quantity' => 1 ),
	)
);
check( 'QO3: simple and variation lines are added through the cart', 2 === $added['added'] && array( 201, 100, 0, array() ) === $cart->added[0] && array( 202, 3, 203, array( 'attribute_pa_size' => 'large' ) ) === $cart->added[1] );
check( 'QO3: a variation named under another product is refused', false === $added['lines'][2]['added'] );
check( 'QO1: a variable parent with no variation is refused', false === $added['lines'][3]['added'] );
check( 'QO: the cart\'s own checks (order limits) run once and come back as warnings', $cart->checked && array( 'Minimum order is 10.' ) === $added['warnings'] );
check( 'QO: the shopper\'s earlier notices are put back', array( 'success' => array( array( 'notice' => 'earlier notice' ) ) ) === $GLOBALS['TACK_WC_NOTICES'] );

$cart2         = new Tack_1120_Cart();
$qo->test_cart = $cart2;
tack_test_add_filter_return( 'woocommerce_add_to_cart_validation', false );
$refused = $qo->add_to_cart( array( array( 'product_id' => 201, 'variation_id' => 0, 'quantity' => 1 ) ) );
tack_test_clear_filter_returns();
check( 'QO3: woocommerce_add_to_cart_validation false -> not added', 0 === $refused['added'] && array() === $cart2->added && ! $cart2->checked );

$cart3         = new Tack_1120_Cart();
$cart3->refuse = true;
$qo->test_cart = $cart3;
$no            = $qo->add_to_cart( array( array( 'product_id' => 201, 'variation_id' => 0, 'quantity' => 1 ) ) );
check( 'QO: WooCommerce\'s own refusal text is returned on the line', 'Sorry, this product cannot be purchased.' === $no['lines'][0]['message'] );

$qo_src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-quick-order.php' );
check( 'QO: both AJAX handlers check the storefront nonce first', 2 === substr_count( $qo_src, "\$this->verify_nonce();\n" ) );
check( 'QO: the lookup is rate limited', false !== strpos( $qo_src, "Tack_Rate_Limit::exceeded( 'tack_qo_', 'quick-order', self::RATE_LIMIT_MAX )" ) );
check( 'QO: both run on wc-ajax (cart session loaded), never nopriv admin-ajax', false !== strpos( $qo_src, "add_action( 'wc_ajax_' . self::ACTION_LOOKUP" ) && false === strpos( $qo_src, 'wp_ajax_nopriv' ) );

// Shortcode + block.
$GLOBALS['TACK_HOOKS']      = array();
$GLOBALS['TACK_SHORTCODES'] = array();
( new Tack_Quick_Order() )->init();
check( 'QO: [tackquote_quick_order] is registered', isset( $GLOBALS['TACK_SHORTCODES']['tackquote_quick_order'] ) );
check( 'QO: the block registers on init', tack_parity_hooked( 'init' ) );
$form_html = ( new Tack_Quick_Order() )->render();
check( 'QO: the form renders its rows, the paste box and both actions', false !== strpos( $form_html, 'data-tack-quick-order' ) && false !== strpos( $form_html, 'tack-quick-order-paste-input' ) && false !== strpos( $form_html, 'tack-quick-order-cart' ) && false !== strpos( $form_html, 'tack-quick-order-quote' ) );

// ── CB1: Add to Quote on block product grids ────────────────────────────────
tack_parity_reset();
$GLOBALS['TACK_TEST_PRODUCTS'] = array( 201 => $simple, 202 => $variable );
$w = new Tack_Widget();
$w->init();
check( 'CB1: the card control follows render_block_woocommerce/product-button (3 args)', 1 === count( array_filter( $GLOBALS['TACK_HOOKS'], function ( $h ) {
	return 'render_block_woocommerce/product-button' === $h['hook'] && 3 === (int) $h['args'];
} ) ) );
$card_block = function ( $id, $context = array() ) use ( $w ) {
	$b          = new stdClass();
	$b->context = array_merge( array( 'postId' => $id ), $context );
	return $w->append_card_to_product_button( '<div class="wp-block-button"><a>Add to cart</a></div>', array( 'blockName' => 'woocommerce/product-button' ), $b );
};
check( 'CB1: off by default (no card control)', '<div class="wp-block-button"><a>Add to cart</a></div>' === $card_block( 201 ) );
tack_test_set_option( Tack_Widget::OPT_CARD_BUTTONS, 'yes' );
$out = $card_block( 201 );
check( 'CB1: a simple product\'s card gets Add to Quote after its button', 1 === substr_count( $out, 'tack-card-quote-btn' ) && 0 === strpos( $out, '<div class="wp-block-button"><a>Add to cart</a></div>' ) && false !== strpos( $out, 'data-product-id="201"' ) );
check( 'CB1: a variable product\'s card links to choose options', false !== strpos( $card_block( 202 ), 'tack-card-quote-link' ) );
check( 'CB1: never inside the Add to Cart with Options form (product page)', false === strpos( $card_block( 201, array( 'woocommerce/isDescendantOfAddToCartWithOptions' => true ) ), 'tack-card-quote' ) );

// Block archive: the compatibility layer fires the classic hook for the SAME card from inside render_block.
$GLOBALS['product']            = $simple;
$GLOBALS['TACK_DOING_FILTER']  = array( 'render_block' );
$classic_after_block           = tack_parity_capture( array( $w, 'render_card_button' ) );
check( 'CB1: on a block archive the classic hook adds no second control to that card', '' === $classic_after_block );
$classic_next_card = tack_parity_capture( array( $w, 'render_card_button' ) );
check( 'CB1: the next card on the archive (no block control) still gets one', 1 === substr_count( $classic_next_card, 'tack-card-quote-btn' ) );
$GLOBALS['TACK_DOING_FILTER'] = array();
$card_block( 201 );
$classic_loop = tack_parity_capture( array( $w, 'render_card_button' ) );
check( 'CB1: a classic product loop elsewhere on the page keeps its control', 1 === substr_count( $classic_loop, 'tack-card-quote-btn' ) );
unset( $GLOBALS['product'] );

// ── CT1: the Cart block control ─────────────────────────────────────────────
if ( ! interface_exists( 'Automattic\\WooCommerce\\Blocks\\Integrations\\IntegrationInterface' ) ) {
	/** Stand-in for Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface (WooCommerce 11.2.1). */
	interface Tack_Stub_Integration_Interface {
		/** @return string */
		public function get_name();
		/** Initialise. */
		public function initialize();
		/** @return string[] */
		public function get_script_handles();
		/** @return string[] */
		public function get_editor_script_handles();
		/** @return array */
		public function get_script_data();
	}
	class_alias( 'Tack_Stub_Integration_Interface', 'Automattic\\WooCommerce\\Blocks\\Integrations\\IntegrationInterface' );
}
/** An IntegrationRegistry as far as register() is concerned. */
class Tack_1120_Registry {
	/** @var array */
	public $registered = array();
	/** @param object $i Integration. @return bool */
	public function register( $i ) {
		$this->registered[] = $i;
		return true;
	}
}
tack_parity_reset();
$reg = new Tack_1120_Registry();
Tack_Quotes::register_cart_block_integration( $reg );
check( 'CT1: cart setting off -> nothing registered on the Cart block', array() === $reg->registered );
tack_test_set_option( Tack_Widget::OPT_CART_BUTTON, 'yes' );
Tack_Quotes::register_cart_block_integration( $reg );
check( 'CT1: cart setting on -> the integration is registered', 1 === count( $reg->registered ) && $reg->registered[0] instanceof Tack_Cart_Quote_Block );
$integration = $reg->registered[0];
check( 'CT1: its script data reaches the client as tackquote-cart-quote_data', 'tackquote-cart-quote' === $integration->get_name() && array( 'tackquote-cart-quote-block' ) === $integration->get_script_handles() );
check( 'CT1: the label defaults to the translated cart-request text', 'Request a quote for your cart' === $integration->get_script_data()['label'] && false !== strpos( $integration->get_script_data()['buttonClass'], 'tack-quote-cart-btn' ) );
tack_test_set_option( Tack_Widget::OPT_CART_BUTTON_LABEL, 'Get a price' );
check( 'CT1: the merchant\'s label wins', 'Get a price' === $integration->get_script_data()['label'] );

$GLOBALS['TACK_IS_CART'] = true;
$GLOBALS['TACK_CART_LINES'] = array(
	'k1' => array(
		'product_id'   => 201,
		'variation_id' => 0,
		'quantity'     => 2,
		'data'         => $simple,
	),
);
Tack_Cart_Quote_Block::reset();
$fw = new Tack_Widget();
$footer = tack_parity_capture( array( $fw, 'render_cart_quote_button_footer' ) );
check( 'CT1: without the Cart block integration the footer control is the fallback', false !== strpos( $footer, 'tack-quote-cart-fixed' ) );
$integration->initialize();
$fw2    = new Tack_Widget();
$footer = tack_parity_capture( array( $fw2, 'render_cart_quote_button_footer' ) );
check( 'P2: WooCommerce initialising the integration (every request) does NOT stand the footer down', false !== strpos( $footer, 'tack-quote-cart-fixed' ) );
check( 'P2: registration watches the Cart block render', tack_parity_hooked( 'render_block_woocommerce/cart' ) );
check( 'P2: the render filter leaves the block as it is', '<div>cart</div>' === Tack_Cart_Quote_Block::mark_rendered( '<div>cart</div>' ) );
$fw3    = new Tack_Widget();
$footer = tack_parity_capture( array( $fw3, 'render_cart_quote_button_footer' ) );
check( 'CT1: once a Cart block with the integration rendered, the footer control stands down', '' === $footer );
Tack_Cart_Quote_Block::reset();
$GLOBALS['TACK_IS_CART']    = false;
$GLOBALS['TACK_CART_LINES'] = array();

$ct_js = (string) file_get_contents( TACK_QUOTES_DIR . 'assets/js/tack-cart-quote-block.js' );
check( 'CT1: the script fills ExperimentalOrderMeta in the woocommerce-checkout scope', false !== strpos( $ct_js, 'wc.blocksCheckout.ExperimentalOrderMeta' ) && false !== strpos( $ct_js, "scope: 'woocommerce-checkout'" ) );
check( 'CT1: the fill renders only in the Cart block', false !== strpos( $ct_js, "props.context !== 'woocommerce/cart'" ) );

// ── NT1 / NT2: net terms in the Checkout block ──────────────────────────────
tack_parity_reset();
$GLOBALS['TACK_OPTIONS'][ 'woocommerce_' . Tack_Gateway_Net_Terms::ID . '_settings' ] = array( 'enabled' => 'yes' );
$nt_block = new Tack_Net_Terms_Block( new Tack_NT_Test_Gateway() );
$nt_block->initialize();
$nt_data = $nt_block->get_payment_method_data();
check( 'NT1: settings with only "enabled" -> the default title, not an empty label', 'Pay on net terms' === $nt_data['title'] );
check( 'NT1: and the default description', 'Place this order on your approved payment terms. We will send an invoice.' === $nt_data['description'] );
$GLOBALS['TACK_OPTIONS'][ 'woocommerce_' . Tack_Gateway_Net_Terms::ID . '_settings' ] = array( 'enabled' => 'yes', 'title' => 'Invoice me' );
$nt_block->initialize();
check( 'NT1: a saved title is kept', 'Invoice me' === $nt_block->get_payment_method_data()['title'] );

$GLOBALS['TACK_OPTIONS'][ 'woocommerce_' . Tack_Gateway_Net_Terms::ID . '_settings' ] = array( 'enabled' => 'no' );
check( 'NT2: gateway disabled -> no Store API data (no standing read)', array() === Tack_Quotes::net_terms_cart_data() );
$GLOBALS['TACK_OPTIONS'][ 'woocommerce_' . Tack_Gateway_Net_Terms::ID . '_settings' ] = array( 'enabled' => 'yes' );
check( 'NT2: gateway enabled but not in WooCommerce\'s list -> false (fail closed), never absent', array( 'netTermsAvailable' => false ) === Tack_Quotes::net_terms_cart_data() );
$nt_js = (string) file_get_contents( TACK_QUOTES_DIR . 'assets/js/tack-net-terms-block.js' );
check( 'NT2: canMakePayment prefers the Store API cart\'s own answer', false !== strpos( $nt_js, 'arg.cart.extensions.tackquote' ) && false !== strpos( $nt_js, "typeof ext.netTermsAvailable === 'boolean'" ) );
check( 'NT2: and falls back to the answer printed with the page', false !== strpos( $nt_js, 'return true === data.canPay;' ) );
$q_src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-quotes.php' );
check( 'NT2: the data is registered on the Store API cart under the tackquote namespace', false !== strpos( $q_src, "woocommerce_store_api_register_endpoint_data(" ) && false !== strpos( $q_src, "const STORE_API_NAMESPACE = 'tackquote';" ) );
check( 'NT2: the answer is the gateway\'s own is_available() (fail closed)', false !== strpos( $q_src, '$available = true === $gateway->is_available();' ) );

// Uninstall forgets the new transients.
$tack_1120_uninstall = (string) file_get_contents( TACK_QUOTES_DIR . 'uninstall.php' );
check( 'uninstall: removes the quote-form cache and its log flag', false !== strpos( $tack_1120_uninstall, "'" . Tack_Quote_Form::CACHE_TRANSIENT . "'" ) && false !== strpos( $tack_1120_uninstall, "'" . Tack_Quote_Form::FILE_FALLBACK_LOGGED . "'" ) );

// P7: a 400 naming the form fields drops the capability cache.
check( 'P7: a 400 naming answers/formVersionId reads as "older server"', Tack_Widget::refused_form_fields( new WP_Error( 'tack_http_400', 'property answers should not exist', array( 'status' => 400 ) ) ) && Tack_Widget::refused_form_fields( new WP_Error( 'tack_http_400', 'property formVersionId should not exist', array( 'status' => 400 ) ) ) );
check( 'P7: another 400 does not', ! Tack_Widget::refused_form_fields( new WP_Error( 'tack_http_400', 'Name and email are required', array( 'status' => 400 ) ) ) && ! Tack_Widget::refused_form_fields( new WP_Error( 'tack_http_502', 'answers', array( 'status' => 502 ) ) ) );
$tack_1120_widget_src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-widget.php' );
check( 'P7: the handler forgets the capabilities and the form on that refusal', false !== strpos( $tack_1120_widget_src, "if ( null !== \$form_answers && self::refused_form_fields( \$result ) ) {\n\t\t\t\t// The capability cache said `quote-form`, the server refused the fields: it is\n\t\t\t\t// an older server now (Fable P7). Next page: the built-in form.\n\t\t\t\t\$client->forget_capabilities();" ) );
// R1: the seller form keeps the posted company details and company name.
check( 'R1: the seller-form branch no longer discards company[...]', 1 !== preg_match( '/\$form_answers = null;.*?\$company\s*= array\(\);.*?\$form_answers = array\(/s', $tack_1120_widget_src ) );
check( 'R1: the company name falls back to the posted policy field when the form has none', false !== strpos( $tack_1120_widget_src, "\$company_name = '' !== \$role_company ? \$role_company : \$company_name;" ) );
// P10: the add handler is rate limited too.
check( 'P10: add to cart is rate limited', false !== strpos( (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-quick-order.php' ), "Tack_Rate_Limit::exceeded( 'tack_qa_', 'quick-order-add', self::RATE_LIMIT_MAX )" ) );
// P8: the upload answer names each file's position.
check( 'P8: the upload answer echoes each file\'s index', false !== strpos( (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-attachments.php' ), "'index'    => (int) \$index," ) );
