<?php

test('the application redirects its domain to the login', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('filament.inicio.auth.login'));
});
