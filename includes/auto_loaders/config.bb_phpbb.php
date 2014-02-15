<?php
/**
 * auto loader for the phpBB observer class. Load this at Breakpoint 54; the base
 * bb class will be instantiated at Breakpoint 55 and 'throw' the first (instantiate)
 * notifier at that point.
 *
 * @copyright Copyright 2013, lat9: Zen Cart/phpBB Integration
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 */
  $autoLoadConfig[54][] = array('autoType'=>'class',
                                'loadFile'=>'observers/class.phpbb_observer.php');
  $autoLoadConfig[54][] = array('autoType'=>'classInstantiate',
                                'className'=>'phpbb_observer',
                                'objectName'=>'phpbb_observer');
// eof