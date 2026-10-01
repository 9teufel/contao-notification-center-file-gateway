<?php

use NineTeufel\NotificationCenterFileGatewayBundle\Gateway\FileGateway;

$GLOBALS['TL_DCA']['tl_nc_message']['palettes'][FileGateway::NAME] = '{title_legend},title,gateway;{languages_legend},languages;{publish_legend},published,start,stop';
