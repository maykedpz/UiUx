<?php
/**
 * Corpmerch category taxonomy data.
 * Tier 1 => Tier 2 => [ Tier 3, ... ]. Used by the category importer.
 *
 * Corporate-merchandise storefront taxonomy. Tier 3 is unused (empty arrays),
 * so the importer creates a clean two-level Category > Sub-category tree.
 *
 * @package Corpmerch
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

function corpmerch_taxonomy_data() {
	return array(
		'Apparel' => array(
			'Bodywarmers' => array(), 'Bottoms' => array(), 'Fleece Tops' => array(),
			'Golf Shirts' => array(), 'Jackets' => array(), 'Kids-Fleece Tops' => array(),
			'Kids-Golf Shirts' => array(), 'Kids-Jackets' => array(), 'Kids-Sweaters' => array(),
			'Kids-T-Shirts' => array(), 'Knitwear' => array(), 'Ladies Corporate Wear' => array(),
			'Schoolwear' => array(), 'Shirts-Corporate' => array(), 'Shirts-Outdoor' => array(),
			'Shirts-Racing' => array(), 'Sweaters' => array(), 'T-Shirts' => array(),
		),
		'Bags' => array(
			'Backpacks' => array(), 'Bags on Wheels' => array(), 'Conference and Messenger Bags' => array(),
			'Drawstrings' => array(), 'Outdoor' => array(), 'Shoppers and Slings' => array(),
			'Sports Bags' => array(), 'Travel Bags' => array(),
		),
		'Chef Wear' => array(
			'Apron' => array(), 'Bottoms' => array(), 'Head Wear Range' => array(), 'Jackets' => array(),
		),
		'Display' => array(
			'Flags' => array(), 'Hardware' => array(), 'Indoor' => array(), 'Outdoor' => array(), 'Skins' => array(),
		),
		'Gifting' => array(
			'Automotive and First Aid' => array(), 'Backpacks' => array(), 'Bags on Wheels' => array(),
			'Braai' => array(), 'Coolers' => array(), 'Diaries' => array(), 'Drawstrings' => array(),
			'Drinkware' => array(), 'Flashlights and Tools' => array(), 'Folders' => array(),
			'Keychains' => array(), 'Kitchen Wine and Food' => array(), 'Ladies Gifts' => array(),
			'Loadshedding' => array(), 'Notebooks' => array(), 'Novelties' => array(),
			'Office Accessories' => array(), 'Outdoor' => array(), 'Pet Care' => array(),
			'Safety Accessories' => array(), 'Shoppers and Slings' => array(), 'Sports Bags' => array(),
			'Technology' => array(), 'Travel' => array(), 'Travel Bags' => array(), 'Umbrellas' => array(),
			'Wine' => array(), 'Writing Instruments' => array(), 'ZClear' => array(),
		),
		'Head Wear' => array(
			'Caps' => array(), 'Outdoor' => array(), 'Safety Range' => array(), 'Winter Range' => array(),
		),
		'Homeware' => array(
			'Appliances' => array(), 'Crockery' => array(), 'Cutlery' => array(),
			'Drinkware' => array(), 'Glassware' => array(), 'Kitchenware' => array(),
		),
		'Sport' => array(
			'Canterbury' => array(), 'Events' => array(), 'Off Field Apparel' => array(),
			'On Field Apparel' => array(), 'RWC 2023 Range' => array(), 'Socks' => array(), 'Sport Bags' => array(),
		),
		'Sublimation' => array(
			'Accessories' => array(), 'Bottoms' => array(), 'Golf Shirts' => array(),
			'On Field Apparel' => array(), 'T-Shirts' => array(),
		),
		'Work Wear' => array(
			'Bottoms' => array(), 'Footwear' => array(), 'High Visibility' => array(),
			'JCB Workwear' => array(), 'Jackets' => array(), 'Pioneer Safety' => array(),
			'Protective Outerwear' => array(), 'Safety Accessories' => array(),
			'Security' => array(), 'Service and Beauty' => array(),
		),
		'Clearance' => array(
			'Apparel Clearance' => array(), 'Bags Clearance' => array(), 'Chef Wear Clearance' => array(),
			'Display Clearance' => array(), 'Gifting Clearance' => array(), 'Head Wear Clearance' => array(),
			'Work Wear Clearance' => array(), 'Sport Clearance' => array(),
		),
	);
}
