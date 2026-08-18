<?php

namespace Paymenter\Extensions\Others\VersionedFixture;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Extension;

#[ExtensionMeta(
    name: 'Versioned Fixture',
    description: 'Upload service version-resolution fixture.',
    version: '1.2.3',
    author: 'Paymenter Tests'
)]
class VersionedFixture extends Extension {}
