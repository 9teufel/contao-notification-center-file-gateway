<?php

use Terminal42\NotificationCenterBundle\Token\TokenContext;
use NineTeufel\NotificationCenterFileGatewayBundle\Gateway\FileGateway;

$GLOBALS['TL_DCA']['tl_nc_language']['palettes'][FileGateway::NAME] = '{general_legend},language,fallback;{tfg_file_legend},tfg_filename,tfg_content';
$GLOBALS['TL_DCA']['tl_nc_language']['fields']['tfg_filename'] = [
    'inputType' => 'text',
    'eval' => ['mandatory' => true, 'maxlength' => 255, 'decodeEntities' => true, 'tl_class' => 'long clr'],
    'nc_context' => TokenContext::Text,
    'sql' => ['type' => 'string', 'length' => 255, 'default' => '', 'notnull' => true],
];
$GLOBALS['TL_DCA']['tl_nc_language']['fields']['tfg_content'] = [
    'inputType' => 'textarea',
    'eval' => ['decodeEntities' => true, 'allowHtml' => true, 'preserveTags' => true, 'tl_class' => 'clr', 'style' => 'font-family:monospace;min-height:300px'],
    'nc_context' => TokenContext::Text,
    'sql' => ['type' => 'text', 'length' => 16777215, 'notnull' => false],
];
