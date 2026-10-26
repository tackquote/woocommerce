<?php
/**
 * 1.12.0 product types (lane WOO-PRODUCT-TYPES): what a quote line is for every
 * WooCommerce product type the quote buttons appear on.
 *
 * The owner's report: a GROUPED product (Logo Collection: Hoodie, T-Shirt, Beanie) with
 * quantities 4 / 6 / 7 became ONE quote line "Logo Collection x 4". WooCommerce adds
 * one cart line per child with a quantity above zero and never the group itself
 * (`WC_Form_Handler::add_to_cart_handler_grouped()`, 11.2.1); a quote does the same now.
 * Also covered: a variation's "Any …" choice kept on its line, a product sold
 * individually quoted as one, the quantity input a quote-only / out-of-stock / external
 * product had none of, and quote-only children of a group.
 *
 * Runs from tests/run.php last (reuses the parity, with-options and standards-audit
 * doubles). Uses check() from there.
 *
 * @package TackQuotes
 */

defined( 'ABSPATH' ) || exit;

/** A product double with what the product-type code reads. */
class Tack_PT_Product extends Tack_WO_Product {
	/** @var bool */
	public $sold_individually = false;
	/** @var bool */
	public $options = false;
	/** @var array Parent attributes (Tack_PT_Attribute). */
	public $attributes = array();
	/** @var int */
	public $max = -1;
	/** @return bool */
	public function is_sold_individually() {
		return $this->sold_individually;
	}
	/** @return bool */
	public function has_options() {
		return $this->options;
	}
	/** @return array */
	public function get_attributes() {
		return $this->attributes;
	}
	/** @return int */
	public function get_max_purchase_quantity() {
		return $this->max;
	}
}

/** A variation double that is a WC_Product_Variation (as resolve_purchasable() requires). */
class Tack_PT_Variation extends WC_Product_Variation {
	/** @var string */
	public $name;
	/** @var string */
	public $sku;
	/** @var float */
	public $price;
	/** @var array<string,string> */
	public $variation_attributes = array();
	/** @var bool */
	public $sold_individually = false;
	/**
	 * @param int    $id     Id.
	 * @param int    $parent Parent id.
	 * @param string $name   Name.
	 * @param string $sku    SKU.
	 * @param float  $price  Price excl. tax.
	 * @param array  $attrs  attribute_* => value ('' = Any).
	 */
	public function __construct( $id, $parent, $name, $sku, $price, $attrs ) {
		parent::__construct( $id );
		$this->parent_id            = $parent;
		$this->name                 = $name;
		$this->sku                  = $sku;
		$this->price                = $price;
		$this->variation_attributes = $attrs;
		// What wc_get_formatted_variation() lists for the variation object (the stub reads
		// `attrs`): its own non-empty values, term names for taxonomy attributes.
		$this->attrs = array();
		foreach ( $attrs as $key => $value ) {
			if ( '' !== $value ) {
				$this->attrs[] = 0 === strpos( $key, 'attribute_pa_' ) ? ucfirst( $value ) : $value;
			}
		}
	}
	/** @var string[] */
	public $attrs = array();
	/** @return string */
	public function get_name() {
		return $this->name;
	}
	/** @return string */
	public function get_sku() {
		return $this->sku;
	}
	/** @return float */
	public function get_price() {
		return $this->price;
	}
	/** @return array */
	public function get_variation_attributes() {
		return $this->variation_attributes;
	}
	/** @param string $t Type. @return bool */
	public function is_type( $t ) {
		return 'variation' === $t;
	}
	/** @return bool */
	public function is_sold_individually() {
		return $this->sold_individually;
	}
}

/** WC_Product_Attribute, as far as the code reads it. */
class Tack_PT_Attribute {
	/** @var string */
	public $name;
	/** @var bool */
	public $taxonomy;
	/** @var array */
	public $values;
	/**
	 * @param string $name     Name (pa_color, or a custom label).
	 * @param bool   $taxonomy A global attribute.
	 * @param array  $values   slug => term name (taxonomy), or the options (custom).
	 */
	public function __construct( $name, $taxonomy, $values ) {
		$this->name     = $name;
		$this->taxonomy = $taxonomy;
		$this->values   = $values;
	}
	/** @return bool */
	public function get_variation() {
		return true;
	}
	/** @return bool */
	public function is_taxonomy() {
		return $this->taxonomy;
	}
	/** @return string */
	public function get_name() {
		return $this->name;
	}
	/** @return array */
	public function get_slugs() {
		return $this->taxonomy ? array_keys( $this->values ) : $this->values;
	}
	/** @return array */
	public function get_options() {
		return $this->values;
	}
	/** @return array */
	public function get_terms() {
		$out = array();
		foreach ( $this->values as $slug => $name ) {
			$t       = new stdClass();
			$t->slug = $slug;
			$t->name = $name;
			$out[]   = $t;
		}
		return $out;
	}
}

// ── The owner's store: Logo Collection ──────────────────────────────────────
$pt_hoodie            = new Tack_PT_Product( 232, 'simple', 'Hoodie with Logo', 'woo-hoodie-with-logo', 45.0 );
$pt_tshirt            = new Tack_PT_Product( 233, 'simple', 'T-Shirt', 'woo-tshirt', 18.0 );
$pt_beanie            = new Tack_PT_Product( 234, 'simple', 'Beanie', 'woo-beanie', 18.0 ); // On sale from 20: get_price() is 18.
$pt_hoodie_variable   = new Tack_PT_Product( 235, 'variable', 'Hoodie', 'woo-hoodie', 42.0 );
$pt_hoodie_variable->options = true;
$pt_draft_child       = new Tack_PT_Product( 236, 'simple', 'Draft child', 'draft', 1.0 );
$pt_group             = new Tack_PT_Product( 252, 'grouped', 'Logo Collection', 'logo-collection', 18.0 );
$pt_group->children   = array( 232, 233, 234, 235, 236 );
foreach ( array( $pt_hoodie, $pt_tshirt, $pt_beanie, $pt_hoodie_variable, $pt_draft_child, $pt_group ) as $pt_p ) {
	$GLOBALS['TACK_TEST_PRODUCTS'][ $pt_p->get_id() ] = $pt_p;
}
// The draft child is hidden from the visitor through WooCommerce's visibility filter.
$GLOBALS['TACK_FILTERS']['woocommerce_product_is_visible'][] = function ( $visible, $id = 0 ) {
	return 236 === (int) $id ? false : $visible;
};

check(
	'grouped: grouped_children() lists the quotable children in the group\'s order, each with its own name, SKU and price',
	array(
		'232' => array( 'n' => 'Hoodie with Logo', 's' => 'woo-hoodie-with-logo', 'p' => 45.0 ),
		'233' => array( 'n' => 'T-Shirt', 's' => 'woo-tshirt', 'p' => 18.0 ),
		'234' => array( 'n' => 'Beanie', 's' => 'woo-beanie', 'p' => 18.0 ),
	) === Tack_Block_Product::grouped_children( $pt_group ),
	var_export( Tack_Block_Product::grouped_children( $pt_group ), true )
);
check( 'grouped: a variable child (WooCommerce shows "Select options" there) is not a group line', ! Tack_Block_Product::groupable( $pt_hoodie_variable ) );
$pt_hoodie_variable->options = false;
check( 'grouped: a variable child is not a group line even when it reports no options', ! Tack_Block_Product::groupable( $pt_hoodie_variable ) );
$pt_hoodie_variable->options = true;
$pt_tshirt->options          = true;
check( 'grouped: a child with options (required add-ons) is not a group line', ! Tack_Block_Product::groupable( $pt_tshirt ) );
$pt_tshirt->options = false;
check( 'grouped: a child the visitor may not see is not a group line', ! Tack_Block_Product::groupable( $pt_draft_child ) );
check( 'grouped: a grouped product inside a group is not a line', ! Tack_Block_Product::groupable( $pt_group ) );
check( 'grouped: grouped_children() is empty for anything else', array() === Tack_Block_Product::grouped_children( $pt_hoodie ) );

// The buttons carry the map; a simple product's do not.
tack_wo_fresh_widget();
$pt_out = tack_parity_capture(
	function () use ( $pt_group ) {
		( new Tack_Widget() )->render_product_button( $pt_group );
	}
);
check( 'grouped: the buttons carry data-tack-group (the children the JS turns into lines)', false !== strpos( $pt_out, 'data-tack-group="{&quot;232&quot;' ), $pt_out );
check( 'grouped: no own quantity input (the quantities are per child)', false === strpos( $pt_out, 'tack_quantity' ), $pt_out );
$pt_out = tack_parity_capture(
	function () use ( $pt_hoodie ) {
		( new Tack_Widget() )->render_product_button( $pt_hoodie );
	}
);
check( 'simple: no group map', false === strpos( $pt_out, 'data-tack-group' ), $pt_out );

// ── Server: the group is never a line, each child is ────────────────────────
$pt_widget = new Tack_Widget();
check( 'grouped: "Request a Quote" for the group itself quotes nothing (was: one line at the cheapest child\'s price)', array() === tack_parity_call( $pt_widget, 'product_line_items', array( 252, 4, 0 ) ) );
$pt_items = tack_parity_call(
	$pt_widget,
	'quote_list_line_items',
	array(
		wp_json_encode(
			array(
				array( 'product_id' => 232, 'quantity' => 4 ),
				array( 'product_id' => 233, 'quantity' => 6 ),
				array( 'product_id' => 234, 'quantity' => 7 ),
				array( 'product_id' => 252, 'quantity' => 4 ),
			)
		),
	)
);
check(
	'grouped: Hoodie x4, T-Shirt x6, Beanie x7 are three lines with their own name, SKU and (sale) price; the group row is dropped',
	array(
		array( 'sku' => 'woo-hoodie-with-logo', 'name' => 'Hoodie with Logo', 'quantity' => 4, 'unitPrice' => 45.0, 'externalProductId' => '232' ),
		array( 'sku' => 'woo-tshirt', 'name' => 'T-Shirt', 'quantity' => 6, 'unitPrice' => 18.0, 'externalProductId' => '233' ),
		array( 'sku' => 'woo-beanie', 'name' => 'Beanie', 'quantity' => 7, 'unitPrice' => 18.0, 'externalProductId' => '234' ),
	) === $pt_items,
	var_export( $pt_items, true )
);

// ── Sold individually ───────────────────────────────────────────────────────
$pt_hoodie->sold_individually = true;
$pt_line                      = Tack_Widget::line_item( $pt_hoodie, 5 );
$pt_hoodie->sold_individually = false;
check( 'sold individually: quoted as one, as WooCommerce sells it', 1 === $pt_line['quantity'] );
$pt_hoodie->sold_individually = true;
$pt_map                       = Tack_Block_Product::grouped_children( $pt_group );
$pt_out                       = tack_parity_capture(
	function () use ( $pt_hoodie ) {
		tack_wo_fresh_widget();
		( new Tack_Widget() )->render_product_button( $pt_hoodie );
	}
);
$pt_hoodie->sold_individually = false;
check( 'sold individually: the group map says at most one (m: 1)', 1 === ( $pt_map['232']['m'] ?? null ) && ! isset( $pt_map['233']['m'] ) );
check( 'sold individually: the Add to Quote button says at most one', false !== strpos( $pt_out, 'data-product-max="1"' ), $pt_out );
check( 'not sold individually: the quantity asked for', 5 === Tack_Widget::line_item( $pt_hoodie, 5 )['quantity'] );

// ── "Any …" attributes ──────────────────────────────────────────────────────
$pt_var_parent             = new Tack_PT_Product( 240, 'variable', 'Polo', 'POLO', 20.0 );
$pt_var_parent->children   = array( 241, 242 );
$pt_var_parent->attributes = array(
	new Tack_PT_Attribute( 'pa_color', true, array( 'blue' => 'Blue', 'red' => 'Red' ) ),
	new Tack_PT_Attribute( 'Size', false, array( 'Large', 'Small' ) ),
);
// 241: Any colour, Large. 242: Red, Small.
$pt_any   = new Tack_PT_Variation( 241, 240, 'Polo - Large', 'POLO-L', 22.0, array( 'attribute_pa_color' => '', 'attribute_size' => 'Large' ) );
$pt_fixed = new Tack_PT_Variation( 242, 240, 'Polo - Red, Small', 'POLO-RS', 21.0, array( 'attribute_pa_color' => 'red', 'attribute_size' => 'Small' ) );
foreach ( array( $pt_var_parent, $pt_any, $pt_fixed ) as $pt_p ) {
	$GLOBALS['TACK_TEST_PRODUCTS'][ $pt_p->get_id() ] = $pt_p;
}
check( 'any: the open attribute keys of a variation', array( 'attribute_pa_color' ) === Tack_Block_Product::any_attribute_keys( $pt_any ) );
check( 'any: a variation with none has none', array() === Tack_Block_Product::any_attribute_keys( $pt_fixed ) );
check( 'any: a value the parent offers is kept', array( 'attribute_pa_color' => 'blue' ) === Tack_Block_Product::chosen_any_attributes( $pt_any, $pt_var_parent, array( 'attribute_pa_color' => 'blue' ) ) );
check( 'any: a value the parent does NOT offer is dropped', array() === Tack_Block_Product::chosen_any_attributes( $pt_any, $pt_var_parent, array( 'attribute_pa_color' => 'green' ) ) );
check( 'any: a value for an attribute the variation fixes is dropped', array() === Tack_Block_Product::chosen_any_attributes( $pt_fixed, $pt_var_parent, array( 'attribute_pa_color' => 'blue' ) ) );
$pt_line = Tack_Widget::line_item( $pt_any, 2, array( 'attribute_pa_color' => 'blue' ) );
check( 'any: the line is named with the shopper\'s choice ("Polo - Blue, Large")', 'Polo - Blue, Large' === $pt_line['name'], $pt_line['name'] );
check( 'any: and quotes the variation itself', '241' === $pt_line['externalProductId'] && 'POLO-L' === $pt_line['sku'] && 22.0 === $pt_line['unitPrice'] );
$pt_line = Tack_Widget::line_item( $pt_any, 2 );
check( 'any: without a choice (an older page) the line keeps the variation\'s own label', 'Polo - Large' === $pt_line['name'], $pt_line['name'] );
check( 'fixed variation: named by the shared formatter', 'Polo - Red, Small' === Tack_Widget::line_item( $pt_fixed, 1 )['name'] );
$pt_items = tack_parity_call(
	$pt_widget,
	'quote_list_line_items',
	array(
		wp_json_encode(
			array(
				array( 'product_id' => 240, 'variation_id' => 241, 'quantity' => 1, 'attributes' => array( 'attribute_pa_color' => 'blue' ) ),
				array( 'product_id' => 240, 'variation_id' => 241, 'quantity' => 3, 'attributes' => array( 'attribute_pa_color' => 'red' ) ),
			)
		),
	)
);
check( 'any: two choices of one variation are two lines, each named by its choice', 2 === count( $pt_items ) && 'Polo - Blue, Large' === $pt_items[0]['name'] && 'Polo - Red, Large' === $pt_items[1]['name'] && 3 === $pt_items[1]['quantity'], var_export( $pt_items, true ) );
check( 'posted attributes: only attribute_* keys, scalar values, bounded', array( 'attribute_pa_color' => 'blue' ) === Tack_Widget::posted_attributes( array( 'attribute_pa_color' => 'blue', 'evil' => 'x', 'attribute_size' => array( 'a' ) ) ) );
check( 'posted attributes: not an object -> none', array() === Tack_Widget::posted_attributes( 'attribute_pa_color=blue' ) );

// variation_lines() reads each child as a WC_Product (a real WC_Product_Variation is one;
// the shared test WC_Product_Variation is not), so these children are product doubles.
/** A variation as a WC_Product double, for the page maps. */
class Tack_PT_Variation_Product extends Tack_PT_Product {
	/** @var array<string,string> */
	public $variation_attributes = array();
	/** @return array */
	public function get_variation_attributes() {
		return $this->variation_attributes;
	}
}
$pt_page_any                       = new Tack_PT_Variation_Product( 241, 'variation', 'Polo - Large', 'POLO-L', 22.0, 240 );
$pt_page_any->variation_attributes = $pt_any->variation_attributes;
$pt_page_any->attrs                = $pt_any->attrs;
$pt_page_fixed                       = new Tack_PT_Variation_Product( 242, 'variation', 'Polo - Red, Small', 'POLO-RS', 21.0, 240 );
$pt_page_fixed->variation_attributes = $pt_fixed->variation_attributes;
$pt_page_fixed->attrs                = $pt_fixed->attrs;
$GLOBALS['TACK_TEST_PRODUCTS'][241] = $pt_page_any;
$GLOBALS['TACK_TEST_PRODUCTS'][242] = $pt_page_fixed;
$pt_lines                           = Tack_Block_Product::variation_lines( $pt_var_parent );
check( 'any: variation_lines() names the open keys of an Any variation (a), and only of one', array( 'attribute_pa_color' ) === ( $pt_lines['241']['a'] ?? null ) && ! isset( $pt_lines['242']['a'] ), var_export( $pt_lines, true ) );
check(
	'any: attribute_terms() names each value as WooCommerce does, in attribute order',
	'{"attribute_pa_color":{"blue":"Blue","red":"Red"},"attribute_size":{"Large":"Large","Small":"Small"}}' === wp_json_encode( Tack_Block_Product::attribute_terms( $pt_var_parent ) ),
	(string) wp_json_encode( Tack_Block_Product::attribute_terms( $pt_var_parent ) )
);
tack_wo_fresh_widget();
$pt_out = tack_parity_capture(
	function () use ( $pt_var_parent ) {
		( new Tack_Widget() )->render_product_button( $pt_var_parent );
	}
);
check( 'any: the buttons of a product with an Any variation carry the value names', false !== strpos( $pt_out, 'data-tack-attribute-terms=' ), $pt_out );
check(
	'cart: a cart line\'s Any choice is carried, its fixed attributes are not',
	array( 'attribute_pa_color' => 'blue' ) === Tack_Widget::cart_any_attributes(
		array(
			'variation_id' => 241,
			'variation'    => array( 'attribute_pa_color' => 'blue', 'attribute_size' => 'Large' ),
		),
		$pt_any
	)
);
$GLOBALS['TACK_TEST_PRODUCTS'][241] = $pt_any;
$GLOBALS['TACK_TEST_PRODUCTS'][242] = $pt_fixed;
check( 'cart: a simple cart line carries none', array() === Tack_Widget::cart_any_attributes( array( 'variation_id' => 0 ), $pt_hoodie ) );

// ── The quantity WooCommerce shows none of ───────────────────────────────────
$pt_external = new Tack_PT_Product( 260, 'external', 'Affiliate Drill', 'AFF-1', 99.0 );
$GLOBALS['TACK_TEST_PRODUCTS'][260] = $pt_external;
check( 'quantity: a purchasable simple product in stock has WooCommerce\'s own', ! Tack_Block_Product::form_omits_quantity( $pt_hoodie ) );
$pt_hoodie->purchasable = false;
check( 'quantity: a quote-only / unpriced simple product has none (simple.php returns early)', Tack_Block_Product::form_omits_quantity( $pt_hoodie ) );
$pt_hoodie->purchasable = true;
$pt_hoodie->in_stock    = false;
check( 'quantity: an out-of-stock simple product has none (no form)', Tack_Block_Product::form_omits_quantity( $pt_hoodie ) );
$pt_hoodie->in_stock = true;
check( 'quantity: an external product has none (a link to the other store)', Tack_Block_Product::form_omits_quantity( $pt_external ) );
check( 'quantity: nor does the Add to Cart with Options block give one to an external product', Tack_Block_Product::with_options_omits_quantity( $pt_external ) );
check( 'quantity: variable and grouped are handled by their own forms', ! Tack_Block_Product::form_omits_quantity( $pt_var_parent ) && ! Tack_Block_Product::form_omits_quantity( $pt_group ) );
$pt_hoodie->max = 10;
$pt_args        = Tack_Block_Product::quote_quantity_args( $pt_hoodie );
$pt_hoodie->max = -1;
check( 'quantity: WooCommerce\'s minimum, no stock maximum (a quote may ask for more), and named so it never posts to a cart', 1 === $pt_args['min_value'] && -1 === $pt_args['max_value'] && 'tack_quantity' === $pt_args['input_name'] );
$pt_hoodie->sold_individually = true;
check( 'quantity: a product sold individually stays at one', 1 === Tack_Block_Product::quote_quantity_args( $pt_hoodie )['max_value'] );
$pt_hoodie->sold_individually = false;

$pt_hoodie->purchasable = false;
tack_wo_fresh_widget();
$pt_out = tack_parity_capture(
	function () use ( $pt_hoodie ) {
		( new Tack_Widget() )->render_product_button( $pt_hoodie );
	}
);
check( 'quantity: a quote-only simple product\'s buttons print their own quantity (was: always 1)', 1 === substr_count( $pt_out, 'name="tack_quantity"' ), $pt_out );
$pt_hoodie->purchasable = true;
$pt_hoodie->in_stock    = false;
tack_wo_fresh_widget();
$pt_out = tack_parity_capture(
	function () use ( $pt_hoodie ) {
		( new Tack_Widget() )->render_product_button( $pt_hoodie );
	}
);
$pt_hoodie->in_stock = true;
check( 'quantity: an out-of-stock simple product\'s too', 1 === substr_count( $pt_out, 'name="tack_quantity"' ), $pt_out );
tack_wo_fresh_widget();
$GLOBALS['TACK_DOING_ACTION'] = array( 'woocommerce_after_add_to_cart_button' );
$pt_out                       = tack_parity_capture(
	function () use ( $pt_external ) {
		$GLOBALS['product'] = $pt_external; // The hook passes no product: the page's.
		( new Tack_Widget() )->render_product_button();
	}
);
$pt_out2 = tack_parity_capture(
	function () use ( $pt_tshirt ) {
		( new Tack_Widget() )->render_product_button( $pt_tshirt );
	}
);
$GLOBALS['TACK_DOING_ACTION'] = array();
check( 'external: no buttons inside its form (a GET to the other store would carry them away)', '' === $pt_out, $pt_out );
tack_wo_fresh_widget();
$pt_out = tack_parity_capture(
	function () use ( $pt_external ) {
		$GLOBALS['product'] = $pt_external; // The summary fallback, after the form.
		( new Tack_Widget() )->render_product_button();
	}
);
check( 'external: the buttons render after the form, with their own quantity', 1 === substr_count( $pt_out, 'class="tack-quote-buttons"' ) && 1 === substr_count( $pt_out, 'name="tack_quantity"' ), $pt_out );
check( 'quantity: a purchasable product\'s buttons inside WooCommerce\'s form print none', false === strpos( $pt_out2, 'tack_quantity' ), $pt_out2 );

// ── Grouped on quote: a quantity where WooCommerce shows "Read more" ─────────
$pt_mode                   = new Tack_Catalog_Mode();
$pt_tshirt->purchasable    = false;
$pt_tshirt->meta[ Tack_Catalog_Mode::META_QUOTE_ONLY ] = 'yes';
$pt_cell                   = $pt_mode->grouped_child_quote_quantity( '<a class="button">Read more</a>', $pt_tshirt );
check( 'grouped on quote: a quote-only child gets tack_quantity[<id>] (not quantity[], which the cart handler reads)', false !== strpos( $pt_cell, 'name="tack_quantity[233]"' ) && false === strpos( $pt_cell, 'Read more' ), $pt_cell );
$pt_tshirt->sold_individually = true;
$pt_cell                      = $pt_mode->grouped_child_quote_quantity( '<a>Read more</a>', $pt_tshirt );
$pt_tshirt->sold_individually = false;
check( 'grouped on quote: a child sold individually gets a checkbox', false !== strpos( $pt_cell, 'type="checkbox" name="tack_quantity[233]" value="1"' ), $pt_cell );
check( 'grouped on quote: a purchasable child keeps WooCommerce\'s cell', 'KEEP' === $pt_mode->grouped_child_quote_quantity( 'KEEP', $pt_beanie ) );
$pt_hoodie_variable->purchasable = false;
check( 'grouped on quote: a variable child keeps WooCommerce\'s "Select options"', 'KEEP' === $pt_mode->grouped_child_quote_quantity( 'KEEP', $pt_hoodie_variable ) );
$pt_hoodie_variable->purchasable = true;
$pt_block          = new stdClass();
$pt_block->context = array( 'postId' => 233 );
$pt_cell           = $pt_mode->grouped_item_selector_quote_quantity( '<div>Read more</div>', array(), $pt_block );
check( 'grouped on quote (block): the item selector of a quote-only child holds the quote quantity', false !== strpos( $pt_cell, 'name="tack_quantity[233]"' ) && false !== strpos( $pt_cell, 'wc-block-add-to-cart-with-options-grouped-product-item-selector' ), $pt_cell );
$pt_block->context = array( 'postId' => 234 );
check( 'grouped on quote (block): a purchasable child\'s selector is untouched', 'KEEP' === $pt_mode->grouped_item_selector_quote_quantity( 'KEEP', array(), $pt_block ) );
unset( $pt_tshirt->meta[ Tack_Catalog_Mode::META_QUOTE_ONLY ] );
$pt_tshirt->purchasable = true;

// ── Store API cart item ─────────────────────────────────────────────────────
$pt_data = Tack_Quotes::cart_item_data(
	array(
		'product_id'   => 240,
		'variation_id' => 241,
		'variation'    => array( 'attribute_pa_color' => 'blue', 'attribute_size' => 'Large' ),
		'data'         => $pt_any,
	)
);
check( 'Store API: a cart item carries its Any choice', array( 'attribute_pa_color' => 'blue' ) === (array) $pt_data['attributes'], var_export( $pt_data, true ) );

// ── The JS reads every quantity of the grouped form ─────────────────────────
$pt_js = preg_replace( '/\s+/', '', (string) file_get_contents( TACK_QUOTES_DIR . 'assets/js/tack-quotes.js' ) );
check( 'JS: both product-page buttons ask for a grouped product\'s rows before anything else', 2 === substr_count( $pt_js, 'constrows=groupedRows($btn);' ) );
check( 'JS: the grouped quantities are quantity[<id>] and tack_quantity[<id>]', false !== strpos( $pt_js, "'input[name^=\"quantity[\"],input[name^=\"tack_quantity[\"]'" ) );
check( 'JS: a grouped "Request a Quote" does not empty the shopper\'s quote list (only list requests do)', false !== strpos( $pt_js, 'if(context.fromList){clearList();}' ) && false === strpos( $pt_js, 'if(context.items){clearList();}' ) );

$GLOBALS['TACK_FILTERS'] = array();
$GLOBALS['product'] = null;

// ── Follow-up: out-of-stock children, dead grouped rows, store-wide grouped form ─
echo "\n-- product types follow-up --\n";
$GLOBALS['TACK_FILTERS'] = array();
$fu_mode                 = new Tack_Catalog_Mode();
$GLOBALS['TACK_TEST_PRODUCTS'][233] = $pt_tshirt;
$pt_tshirt->in_stock     = false;
$fu_cell                 = $fu_mode->grouped_child_quote_quantity( '<a class="button">Read more</a>', $pt_tshirt );
check( 'out of stock: a purchasable child out of stock gets the quote quantity (grouped.php shows "Read more")', false !== strpos( $fu_cell, 'name="tack_quantity[233]"' ) && false === strpos( $fu_cell, 'Read more' ), $fu_cell );
$fu_block          = new stdClass();
$fu_block->context = array( 'postId' => 233 );
$fu_cell           = $fu_mode->grouped_item_selector_quote_quantity( '<div>Read more</div>', array(), $fu_block );
check( 'out of stock (block): the item selector of an out-of-stock child holds the quote quantity', false !== strpos( $fu_cell, 'name="tack_quantity[233]"' ), $fu_cell );
$GLOBALS['TACK_OPTIONS']['tack_quotes_show_add_to_quote']  = 'no';
$GLOBALS['TACK_OPTIONS']['tack_quotes_show_request_quote'] = 'no';
check( 'out of stock: no quote quantity when both quote buttons are off', 'KEEP' === $fu_mode->grouped_child_quote_quantity( 'KEEP', $pt_tshirt ) );
$GLOBALS['TACK_OPTIONS']['tack_quotes_show_add_to_quote']  = 'yes';
$GLOBALS['TACK_OPTIONS']['tack_quotes_show_request_quote'] = 'yes';
$pt_tshirt->purchasable = false;
check( 'out of stock and not purchasable for another reason (not on quote): left to WooCommerce', 'KEEP' === $fu_mode->grouped_child_quote_quantity( 'KEEP', $pt_tshirt ) );
$pt_tshirt->purchasable = true;
$pt_tshirt->in_stock    = true;
check( 'in stock and purchasable: WooCommerce\'s own quantity is kept', 'KEEP' === $fu_mode->grouped_child_quote_quantity( 'KEEP', $pt_tshirt ) );

// Re-price: rows the request would quote no line for.
$fu_widget  = new Tack_Widget();
$fu_resolve = function ( $row ) use ( $fu_widget ) {
	return tack_parity_call( $fu_widget, 'resolve_row', array( $row + array( 'variation_id' => 0, 'quantity' => 1, 'target_price' => null, 'attributes' => array() ) ) );
};
list( $fu_p, $fu_gone ) = $fu_resolve( array( 'product_id' => 252 ) );
check( 'dead row: a grouped parent left in the list is dropped, reason grouped', null === $fu_p && array( 'product_id' => 252, 'variation_id' => 0, 'reason' => 'grouped' ) === $fu_gone, var_export( $fu_gone, true ) );
list( $fu_p, $fu_gone ) = $fu_resolve( array( 'product_id' => 999999 ) );
check( 'dead row: a deleted product is dropped, reason gone', null === $fu_p && 'gone' === $fu_gone['reason'] );
// resolve_purchasable() refuses a WC_Product_Variable without a variation; the shared
// doubles are not one, so this parent is (WooCommerce's class is absent in these tests).
if ( ! class_exists( 'WC_Product_Variable' ) ) {
	/** WooCommerce's variable product class, as far as instanceof reads it. */
	class WC_Product_Variable extends Tack_PT_Product {}
}
$fu_variable                        = new WC_Product_Variable( 240, 'variable', 'Polo', 'POLO', 20.0 );
$GLOBALS['TACK_TEST_PRODUCTS'][240] = $fu_variable;
list( $fu_p, $fu_gone ) = $fu_resolve( array( 'product_id' => 240 ) );
check( 'dead row: a variable product with no variation, reason no_variation', null === $fu_p && 'no_variation' === $fu_gone['reason'], var_export( $fu_gone, true ) );
list( $fu_p, $fu_gone ) = $fu_resolve( array( 'product_id' => 240, 'variation_id' => 888888 ) );
check( 'dead row: a variation that no longer exists, reason gone', null === $fu_p && 'gone' === $fu_gone['reason'], var_export( $fu_gone, true ) );
$GLOBALS['TACK_TEST_PRODUCTS'][240] = $pt_var_parent;
// Hidden for now (a draft, a password, a group restriction): kept in the list, not dropped.
$GLOBALS['TACK_FILTERS']['woocommerce_product_is_visible'][] = function ( $visible, $id = 0 ) {
	return 236 === (int) $id ? false : $visible;
};
list( $fu_p, $fu_gone ) = $fu_resolve( array( 'product_id' => 236 ) );
check( 'hidden row: a product this buyer may not quote right now is reason hidden (the list keeps it)', null === $fu_p && 'hidden' === $fu_gone['reason'], var_export( $fu_gone, true ) );
$GLOBALS['TACK_FILTERS'] = array();
list( $fu_p, $fu_gone ) = $fu_resolve( array( 'product_id' => 233 ) );
check( 'live row: a child of the group is quoted', $pt_tshirt === $fu_p && null === $fu_gone );
$fu_src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-widget.php' );
$fu_js = preg_replace( '/\s+/', '', (string) file_get_contents( TACK_QUOTES_DIR . 'assets/js/tack-quotes.js' ) );
check( 'dead row (JS): the re-price answer drops the rows and, when all were grouped, shows the grouped message', false !== strpos( $fu_js, 'constfresh=dropped.kept;' ) && false !== strpos( $fu_js, 'if(dropped.allGrouped){listNotice=TackQuotes.i18n.chooseQuantities;}' ) );
check( 'list note: emptying the list clears the note (no launcher left at count 0)', false !== strpos( $fu_js, "functionclearList(){listNotice='';saveList([]);}" ) );
check( 'list note: the quote page shows the same note', false !== strpos( $fu_js, "let\$note=\$page.find('.tack-quote-list-notice');" ) && false !== strpos( $fu_js, "if(listNotice){\$note.text(listNotice);}else{\$note.remove();}" ) );
check( 'dead row: both re-price answers carry `dropped`', 2 === substr_count( $fu_src, "'dropped' => \$dropped," ) );

// Store-wide quote-only grouped form: only when this plugin is why the group cannot be bought.
if ( ! function_exists( 'woocommerce_grouped_add_to_cart' ) ) {
	/** WooCommerce's grouped add-to-cart renderer: counts calls. */
	function woocommerce_grouped_add_to_cart() {
		$GLOBALS['TACK_FU_GROUPED_FORMS'] = ( $GLOBALS['TACK_FU_GROUPED_FORMS'] ?? 0 ) + 1;
	}
}
$GLOBALS['TACK_FU_GROUPED_FORMS']                        = 0;
$fu_caps                                                 = $GLOBALS['TACK_CAPS'];
$GLOBALS['TACK_CAPS']                                    = array(); // A shopper, not the shop manager (exempt).
$GLOBALS['TACK_OPTIONS'][ Tack_Catalog_Mode::OPT_MODE ]  = Tack_Catalog_Mode::MODE_QUOTE_ONLY;
$GLOBALS['TACK_OPTIONS'][ Tack_Catalog_Mode::OPT_SCOPE ] = Tack_Catalog_Mode::SCOPE_EVERYONE;
$GLOBALS['product']                                      = $pt_group;
// A real WC_Product_Grouped is never purchasable unless a filter says so
// (`is_purchasable()` returns `apply_filters( 'woocommerce_is_purchasable', false, … )`,
// WooCommerce 11.2.1); this double stands in for a store where one does, to prove the
// quote form is not rendered for a group the cart could still take.
$pt_group->purchasable                                   = true;
( new Tack_Catalog_Mode() )->render_quote_only_grouped_form();
$fu_when_purchasable    = $GLOBALS['TACK_FU_GROUPED_FORMS'];
$pt_group->purchasable  = false;
( new Tack_Catalog_Mode() )->render_quote_only_grouped_form();
check( 'store-wide: the grouped form comes back only for a group on quote (as the variation form)', 0 === $fu_when_purchasable && 1 === $GLOBALS['TACK_FU_GROUPED_FORMS'], 'purchasable: ' . $fu_when_purchasable . ', on quote: ' . $GLOBALS['TACK_FU_GROUPED_FORMS'] );
unset( $GLOBALS['TACK_OPTIONS'][ Tack_Catalog_Mode::OPT_MODE ], $GLOBALS['TACK_OPTIONS'][ Tack_Catalog_Mode::OPT_SCOPE ] );
$pt_group->purchasable = true;
$GLOBALS['product']    = null;
$GLOBALS['TACK_CAPS']  = $fu_caps;
