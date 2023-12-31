<?php

use GraphQLRelay\Relay;

class ProductVariationQueriesTest extends \Codeception\TestCase\WPTestCase {
	private $shop_manager;
	private $customer;
	private $products;

	public function setUp(): void {
		parent::setUp();

		$this->shop_manager   = $this->factory->user->create( [ 'role' => 'shop_manager' ] );
		$this->customer       = $this->factory->user->create( [ 'role' => 'customer' ] );
		$this->product_helper = $this->getModule( '\Helper\Wpunit' )->product();
		$this->helper         = $this->getModule( '\Helper\Wpunit' )->product_variation();
		$this->products       = $this->helper->create( $this->product_helper->create_variable() );

		\WPGraphQL::clear_schema();
	}

	// tests
	public function testVariationQuery() {
		$variation_id = $this->products['variations'][0];
		$id           = $this->helper->to_relay_id( $variation_id );
		$query        = '
            query ($id: ID, $idType: ProductVariationIdTypeEnum) {
                productVariation(id: $id, idType: $idType) {
                    id
                    databaseId
                    name
                    date
                    modified
                    description
                    sku
                    price
                    regularPrice
                    salePrice
                    dateOnSaleFrom
                    dateOnSaleTo
                    onSale
                    status
                    purchasable
                    virtual
                    downloadable
                    downloadLimit
                    downloadExpiry
                    taxStatus
                    taxClass
                    manageStock
                    stockQuantity
                    stockStatus
                    backorders
                    backordersAllowed
                    weight
                    length
                    width
                    height
                    menuOrder
                    purchaseNote
                    shippingClass
                    catalogVisibility
                    hasAttributes
                    type
                    parent {
						node { id }
                    }
                }
            }
        ';

		/**
		 * Assertion One
		 *
		 * Tests "ID" ID type.
		 */
		$variables = [
			'id'     => $id,
			'idType' => 'ID',
		];
		$actual    = graphql(
			[
				'query'     => $query,
				'variables' => $variables,
			]
		);
		$expected  = [ 'data' => [ 'productVariation' => $this->helper->print_query( $variation_id ) ] ];

		// use --debug flag to view.
		codecept_debug( $actual );

		$this->assertEquals( $expected, $actual );

		$this->getModule( '\Helper\Wpunit' )->clear_loader_cache( 'wc_post' );

		/**
		 * Assertion Two
		 *
		 * Tests "DATABASE_ID" ID type.
		 */
		$variables = [
			'id'     => $variation_id,
			'idType' => 'DATABASE_ID',

		];
		$actual   = graphql(
			[
				'query'     => $query,
				'variables' => $variables,
			]
		);
		$expected = [ 'data' => [ 'productVariation' => $this->helper->print_query( $variation_id ) ] ];

		// use --debug flag to view.
		codecept_debug( $actual );

		$this->assertEquals( $expected, $actual );
	}

	public function testVariationsQueryAndWhereArgs() {
		$id         = $this->product_helper->to_relay_id( $this->products['product'] );
		$product    = wc_get_product( $this->products['product'] );
		$variations = $this->products['variations'];

		$query = '
            query (
                $id: ID!,
                $minPrice: Float,
                $parent: Int,
                $parentIn: [Int],
                $parentNotIn: [Int]
            ) {
                product( id: $id ) {
                    ... on VariableProduct {
                        price
                        regularPrice
                        salePrice
                        variations( where: {
                            minPrice: $minPrice,
                            parent: $parent,
                            parentIn: $parentIn,
                            parentNotIn: $parentNotIn
                        } ) {
                            nodes {
                                id
                            }
                        }
                    }
                }
            }
        ';

		/**
		 * Assertion One
		 *
		 * Test query with no arguments
		 */
		wp_set_current_user( $this->shop_manager );
		$variables = [ 'id' => $id ];
		$actual    = graphql(
			[
				'query'     => $query,
				'variables' => $variables,
			]
		);

		// use --debug flag to view.
		codecept_debug( $actual );

		// Get product data.
		$product_data = $actual['data']['product'];

		// Assert variations.
		foreach ( $variations as $vid ) {
			$this->assertTrue(
				in_array(
					[ 'id' => $this->helper->to_relay_id( $vid ) ],
					$product_data['variations']['nodes'],
					true
				),
				$this->helper->to_relay_id( $vid ) . ' not a variation of ' . $product->get_name()
			);
		}

		// Assert prices.
		$prices         = $this->product_helper->field( $this->products['product'], 'variation_prices', [ true ] );
		$expected_price = \wc_graphql_price( current( $prices['price'] ) )
			. ' - '
			. \wc_graphql_price( end( $prices['price'] ) );
		$this->assertTrue( $expected_price === $product_data['price'] );

		$expected_price = \wc_graphql_price( current( $prices['regular_price'] ) )
			. ' - '
			. \wc_graphql_price( end( $prices['regular_price'] ) );
		$this->assertTrue( $expected_price === $product_data['regularPrice'] );

		$this->assertTrue( null === $product_data['salePrice'] );

		/**
		 * Assertion Two
		 *
		 * Test "minPrice" where argument
		 */
		$variables = [
			'id'       => $id,
			'minPrice' => 15,
		];
		$actual    = graphql(
			[
				'query'     => $query,
				'variables' => $variables,
			]
		);

		// use --debug flag to view.
		codecept_debug( $actual );

		// Get product data.
		$product_data = $actual['data']['product'];

		// Assert variations.
		$filter = function( $id ) {
			$variation = new WC_Product_Variation( $id );
			return 15.00 <= floatval( $variation->get_price() );
		};

		foreach ( array_filter( $variations, $filter ) as $vid ) {
			$this->assertTrue(
				in_array(
					[ 'id' => $this->helper->to_relay_id( $vid ) ],
					$product_data['variations']['nodes'],
					true
				),
				$this->helper->to_relay_id( $vid ) . ' not a variation of ' . $product->get_name()
			);
		}
	}

	public function testProductVariationToMediaItemConnections() {
		$id    = $this->helper->to_relay_id( $this->products['variations'][1] );
		$query = '
			query ($id: ID!) {
				productVariation(id: $id) {
					id
					image {
						id
					}
				}
			}
		';

		$variables = [ 'id' => $id ];
		$actual    = graphql(
			[
				'query'     => $query,
				'variables' => $variables,
			]
		);
		$expected  = [
			'data' => [
				'productVariation' => [
					'id'    => $id,
					'image' => [
						'id' => Relay::toGlobalId(
							'post',
							$this->helper->field( $this->products['variations'][1], 'image_id' )
						),
					],
				],
			],
		];

		// use --debug flag to view.
		codecept_debug( $actual );

		$this->assertEquals( $expected, $actual );
	}

	public function testProductVariationDownloads() {
		$id = $this->helper->to_relay_id( $this->products['variations'][0] );

		$query = '
			query ($id: ID!) {
				productVariation(id: $id) {
					id
					downloads {
						name
						downloadId
						filePathType
						fileType
						fileExt
						allowedFileType
						fileExists
						file
					}
				}
			}
		';

		$variables = [ 'id' => $id ];
		$actual    = graphql(
			[
				'query'     => $query,
				'variables' => $variables,
			]
		);
		$expected  = [
			'data' => [
				'productVariation' => [
					'id'        => $id,
					'downloads' => $this->helper->print_downloads( $this->products['variations'][0] ),
				],
			],
		];

		// use --debug flag to view.
		codecept_debug( $actual );

		$this->assertEquals( $expected, $actual );
	}
}
