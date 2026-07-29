<?php
if('cli'!==PHP_SAPI){http_response_code(403);exit;}
require dirname(__DIR__,4).'/wp-load.php';
$u=get_users(['role'=>'administrator','number'=>1]); wp_set_current_user($u[0]->ID);
$c = \Yazan\Rewards\Core\Plugin::instance()->container();
$embed = $c->get(\Yazan\Rewards\Admin\DashboardEmbed::class);
$out = dirname(__DIR__).'/scratchpad/';
foreach(['points'=>'dark'] as $screen=>$theme){
  $html = $embed->render_html($screen, $theme);
  file_put_contents($out."embed-$screen-$theme.html", $html);
  echo "wrote embed-$screen-$theme.html (".strlen($html)." bytes)\n";
}
