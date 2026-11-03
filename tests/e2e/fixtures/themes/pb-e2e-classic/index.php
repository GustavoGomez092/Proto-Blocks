<?php
/**
 * PB E2E Classic: the minimum a classic theme needs.
 *
 * @package PbE2eClassic
 */

?><!doctype html>
<html <?php language_attributes(); ?>>
<head><?php wp_head(); ?></head>
<body <?php body_class(); ?>>
<?php
while (have_posts()) {
    the_post();
    the_title('<h1>', '</h1>');
    the_content();
}
wp_footer();
?>
</body>
</html>
