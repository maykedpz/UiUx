<?php
/**
 * Title: Hero — Category Rail + Marketplace Pitch
 * Slug: honestly-market/hero-category-marketplace
 * Categories: honestly-market
 * Block Types: core/template-part/front-page
 */
?>
<!-- wp:group {"tagName":"section","className":"hero-section","layout":{"type":"constrained","contentSize":"1400px"}} -->
<section class="wp-block-group hero-section">

<!-- wp:group {"tagName":"aside","className":"category-rail"} -->
<aside class="wp-block-group category-rail">

<!-- wp:heading {"level":2,"className":"rail-heading"} -->
<h2 class="rail-heading">Browse</h2>
<!-- /wp:heading -->

<!-- wp:html -->
<!--
	Static for Phase 1 (look and feel only). In Phase 2 this list is
	replaced by a live query against the WooCommerce product_cat
	taxonomy once the marketplace categories are created as real terms.
-->
<ul class="category-list">
	<li><a href="#">Electronics &amp; Computers<span class="chev" aria-hidden="true">›</span></a></li>
	<li><a href="#">Home, Kitchen &amp; Appliances<span class="chev" aria-hidden="true">›</span></a></li>
	<li><a href="#">Home Improvement, Tools &amp; Garden<span class="chev" aria-hidden="true">›</span></a></li>
	<li><a href="#">Fashion<span class="chev" aria-hidden="true">›</span></a></li>
	<li><a href="#">Beauty &amp; Personal Care<span class="chev" aria-hidden="true">›</span></a></li>
	<li><a href="#">Health &amp; Wellness<span class="chev" aria-hidden="true">›</span></a></li>
	<li><a href="#">Baby, Kids &amp; Toys<span class="chev" aria-hidden="true">›</span></a></li>
	<li><a href="#">Books, Media &amp; Stationery<span class="chev" aria-hidden="true">›</span></a></li>
	<li><a href="#">Sport &amp; Outdoor<span class="chev" aria-hidden="true">›</span></a></li>
	<li><a href="#">Groceries &amp; Household<span class="chev" aria-hidden="true">›</span></a></li>
	<li><a href="#">Pet Supplies<span class="chev" aria-hidden="true">›</span></a></li>
	<li><a href="#">Auto Parts &amp; Accessories<span class="chev" aria-hidden="true">›</span></a></li>
	<li><a href="#">Arts, Crafts &amp; Hobbies<span class="chev" aria-hidden="true">›</span></a></li>
	<li><a href="#">Luggage &amp; Travel<span class="chev" aria-hidden="true">›</span></a></li>
	<li><a href="#">Gift Cards &amp; Vouchers<span class="chev" aria-hidden="true">›</span></a></li>
</ul>
<!-- /wp:html -->

</aside>
<!-- /wp:group -->

<!-- wp:group {"tagName":"div","className":"hero-main"} -->
<div class="wp-block-group hero-main">

<!-- wp:paragraph {"className":"eyebrow"} -->
<p class="eyebrow">A marketplace built on one rule</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":1,"className":"hero-heading","fontSize":"hero"} -->
<h1 class="hero-heading has-hero-font-size">Honestly, that's the price.</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"className":"hero-sub"} -->
<p class="hero-sub">No inflated &#8220;was&#8221; prices. No countdown gimmicks. No auctions. Compare real sellers side by side and pick the real best price.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"className":"hero-ctas"} -->
<div class="wp-block-buttons hero-ctas">
<!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#shop">Start shopping</a></div>
<!-- /wp:button -->

<!-- wp:button {"className":"is-style-outline"} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#sell">Sell on Honestly Market</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->

<!-- wp:html -->
<div class="receipt-card" role="list" aria-label="What makes this marketplace different">
	<div class="receipt-row" role="listitem"><span>No fake &#8220;was&#8221; prices</span><span class="tick" aria-hidden="true">✓</span></div>
	<div class="receipt-row" role="listitem"><span>No auctions</span><span class="tick" aria-hidden="true">✓</span></div>
	<div class="receipt-row" role="listitem"><span>Multi-seller price compare</span><span class="tick" aria-hidden="true">✓</span></div>
	<div class="receipt-row" role="listitem"><span>South African sellers</span><span class="tick" aria-hidden="true">✓</span></div>
</div>
<!-- /wp:html -->

</div>
<!-- /wp:group -->

</section>
<!-- /wp:group -->
