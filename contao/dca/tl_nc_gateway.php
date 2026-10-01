<?php

use NineTeufel\NotificationCenterFileGatewayBundle\Gateway\FileGateway;

$GLOBALS['TL_DCA']['tl_nc_gateway']['palettes'][FileGateway::NAME] = '{title_legend},title,type;{tfg_file_legend},tfg_directory,tfg_mode';
$GLOBALS['TL_DCA']['tl_nc_gateway']['fields']['tfg_directory'] = [
    'inputType' => 'fileTree',
    'eval' => ['mandatory' => true, 'fieldType' => 'radio', 'multiple' => false, 'files' => false, 'tl_class' => 'clr'],
    'sql' => ['type' => 'binary', 'length' => 16, 'fixed' => true, 'notnull' => false],
];
$GLOBALS['TL_DCA']['tl_nc_gateway']['fields']['tfg_mode'] = [
    'inputType' => 'select',
    'options' => ['create', 'overwrite'],
    'reference' => &$GLOBALS['TL_LANG']['tl_nc_gateway']['tfg_mode_options'],
    'default' => 'create',
    'eval' => ['mandatory' => true, 'tl_class' => 'w50'],
    'sql' => ['type' => 'string', 'length' => 16, 'default' => 'create'],
];
