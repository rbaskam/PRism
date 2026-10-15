<?php

it('sends first-time users to connect GitHub', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('onboarding.github'));
});
