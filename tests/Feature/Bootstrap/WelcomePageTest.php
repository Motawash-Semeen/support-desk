<?php

test('guests can open the public welcome page', function () {
    $this->get('/')->assertOk();
});
