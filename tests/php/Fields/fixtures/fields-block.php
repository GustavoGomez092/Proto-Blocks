<?php
/**
 * Fixture: a block template bound with the legacy proto-edit / proto-repeater
 * attributes, which are the only binding Renderer::updateFields() honours and
 * therefore the only way block attributes reach the PHP field layer.
 *
 * Every element is deliberately left unescaped: the point of the tests is
 * that the FIELD classes make raw attribute JSON safe, not the template.
 */
?>
<div class="pb-fields-fixture" data-proto-block="fields-fixture">
    <a class="link" proto-edit="link" proto-type="link" href="#">default link</a>
    <div class="inner" proto-edit="content" proto-type="inner-blocks"><p>default inner</p></div>
    <video class="video" controls><source proto-edit="video" proto-type="video" src=""></video>
    <ul class="items" proto-repeater="items">
        <li class="item"><span class="item-title" proto-edit="title">T</span> <a class="item-link" proto-edit="link" proto-type="link" href="#">L</a></li>
    </ul>
</div>
