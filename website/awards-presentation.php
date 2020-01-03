<?php @session_start();
// Controls the "current award" kiosk display
require_once('inc/data.inc');
require_once('inc/classes.inc');
require_once('inc/banner.inc');
require_once('inc/authorize.inc');
require_once('inc/schema_version.inc');
require_once('inc/standings.inc');
require_once('inc/ordinals.inc');
require_once('inc/awards.inc');
require_once('inc/aggregate_round.inc');

require_permission(PRESENT_AWARDS_PERMISSION);
?><!DOCTYPE html>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
<title>Awards Presentation Dashboard</title><?php require('inc/stylesheet.inc'); ?>
<script type="text/javascript" src="js/jquery.js"></script>
<script type="text/javascript" src="js/ajax-setup.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.10.4.min.js"></script>
<script type="text/javascript" src="js/dashboard-ajax.js"></script>
<script type="text/javascript" src="js/mobile-init.js"></script>
<script type="text/javascript" src="js/jquery.mobile-1.4.2.min.js"></script>
<script type="text/javascript" src="js/awards-presentation.js"></script>
<?php
    try {
      $nkiosks = read_single_value('SELECT COUNT(*) FROM Kiosks'
                                   .' WHERE page LIKE \'%award%present%\'', array());
    } catch (PDOException $p) {
      if ($p->getCode() == '42S02') {
        create_kiosk_table();
      }
      $nkiosks = 0;
    }

    if ($nkiosks == 0) {
      echo '<script type="text/javascript">'."\n";
      echo '$(window).load(function() {'."\n";
      echo '  setTimeout(function() {'."\n";
      echo '  alert("NOTE: There are NO kiosks ready for award presentation."+'."\n";
      echo '        "  Selections on this dashboard won\'t have any observable effect.");'."\n";
      echo '}, 500); });'."\n";
      echo '</script>'."\n";
    }
?>
<link rel="stylesheet" type="text/css" href="css/jquery.mobile-1.4.2.css"/>
<link rel="stylesheet" type="text/css" href="css/awards-presentation.css"/>
</head>
<body>
<?php make_banner('Awards Presentation');

$use_subgroups = read_raceinfo_boolean('use-subgroups');

list($classes, $classseq, $ranks, $rankseq) = classes_and_ranks();

$awards = array();
$bias_overall = add_speed_awards($awards);
$awards = array_merge($awards,
                      all_awards(/* include_ad_hoc */ true,
                                 $bias_overall));

$awards_per_class = array();
$awards_per_rank = array();
$supergroup_awards = 0;
foreach ($awards as $aw) {
  if (isset($aw['rankid'])) {
    $r = @$awards_per_rank[$aw['rankid']];
    if (!isset($r)) {
      $r = 0;
    }
    $awards_per_rank[$aw['rankid']] = $r + 1;
  }
  if (isset($aw['classid'])) {
    $c = @$awards_per_class[$aw['classid']];
    if (!isset($c)) {
      $c = 0;
    }
    $awards_per_class[$aw['classid']] = $c + 1;
  } else {
    ++$supergroup_awards;
  }
}

function compare_by_sort(&$lhs, &$rhs) {
  if ($lhs['sort'] != $rhs['sort']) {
    return $lhs['sort'] < $rhs['sort'] ? -1 : 1;
  }
  if ($lhs['lastname'] != $rhs['lastname']) {
    return $lhs['lastname'] < $rhs['lastname'] ? -1 : 1;
  }
  if ($lhs['firstname'] != $rhs['firstname']) {
    return $lhs['firstname'] < $rhs['firstname'] ? -1 : 1;
  }
  return 0;
}

// This shouldn't actually do anything:
usort($awards, 'compare_by_sort');
?>

<div class="block_buttons">

<div class="center-select">
    <select id="awardtype-select">
        <option selected="Selected">All Awards</option>
        <?php
        foreach ($db->query('SELECT awardtypeid, awardtype FROM AwardTypes ORDER BY awardtype') as $atype) {
          echo '<option data-awardtypeid="'.$atype['awardtypeid'].'">'
              .htmlspecialchars($atype['awardtype'], ENT_QUOTES, 'UTF-8')
              .'</option>'."\n";
        }
        ?>
    </select>
</div>

<div class="center-select">
    <select id="group-select">
      <option selected="Selected">All</option>
      <?php
        if ($supergroup_awards != 0) {
          echo "<option data-supergroup=\"1\">".supergroup_label()."</option>\n";
        }

        $rankseq_index = 0;
        foreach ($classseq as $classid) {
          $cl = $classes[$classid];
          if (@$awards_per_class[$classid] > 0) {
             echo '<option data-classid="'.$classid.'">'
                .htmlspecialchars($cl['class'], ENT_QUOTES, 'UTF-8')
              .'</option>'."\n";
          }
          if ($use_subgroups) {
            for (; $rankseq_index < count($rankseq); ++$rankseq_index) {
              $rank = $ranks[$rankseq[$rankseq_index]];
              if ($rank['classid'] != $classid) {
                break;
              }
              if (@$awards_per_rank[$rank['rankid']] > 0) {
                echo '<option data-rankid="'.$rank['rankid'].'">'
                    .'&nbsp;&nbsp;'
                    .htmlspecialchars($rank['rank'], ENT_QUOTES, 'UTF-8')
                    .'</option>'."\n";
              }
            }
          }
        }
        ?>
    </select>
</div>

<div class="listview">
<ul data-role="listview" class="ui-listview">
<?php

foreach ($awards as &$row) {
   $classid = isset($row['classid']) ? $row['classid'] : 0;
   $rankid = (isset($row['rankid']) && $use_subgroups) ? $row['rankid'] : 0;
   echo '<li class="ui-btn ui-btn-icon-right ui-icon-carat-r'.($row['awardtypeid'] == AD_HOC_AWARDTYPEID ? ' adhoc' : '').'"'
        .' onclick="on_choose_award(this);"'
        .' data-awardkey="'.$row['awardkey'].'"'
        .' data-awardtypeid="'.$row['awardtypeid'].'"'
        .' data-classid="'.$classid.'"'  // 0 except for class-level award
        .' data-rankid="'.$rankid.'"'  // 0 except for rank-level award
        .' data-awardname="'.htmlspecialchars($row['awardname'], ENT_QUOTES, 'UTF-8').'"'
        .' data-recipient="'.htmlspecialchars($row['firstname'].' '.$row['lastname'],
                                              ENT_QUOTES, 'UTF-8').'"'
        .' data-carnumber="'.$row['carnumber'].'"'
        .' data-carname="'.htmlspecialchars($row['carname'], ENT_QUOTES, 'UTF-8').'"'
        .($classid == 0 ? '' :
          ' data-class="'.htmlspecialchars($classes[$classid]['class'], ENT_QUOTES, 'UTF-8').'"')
        .($rankid == 0 ? '' :
          ' data-rank="'.htmlspecialchars($ranks[$rankid]['rank'], ENT_QUOTES, 'UTF-8').'"')
        .'>';
    echo '<span>'.htmlspecialchars($row['awardname'], ENT_QUOTES, 'UTF-8').'</span>';
    echo '<p><strong>'.$row['carnumber'].':</strong> ';
    echo htmlspecialchars($row['firstname'].' '.$row['lastname'], ENT_QUOTES, 'UTF-8');
    echo '</p>';
    echo '</li>';
}
?>
</ul>
</div>

<div class="presenter">

<div id="kiosk-summary">
<?php
        // TODO Kiosks table may not exist
    $nkiosks = read_single_value('SELECT COUNT(*) FROM Kiosks'
                                 .' WHERE page LIKE \'%award%present%\'', array());
    if ($nkiosks == 0) {
      echo '<h3>NOTE:</h3>';
      echo '<h3>There are NO kiosks ready for award presentation.</h3>';
      echo '<p class="moot">Selections on this dashboard won\'t have any observable effect.</p>';
      echo '<p class="moot">Visit the <a href="kiosk-dashboard.php">Kiosk Dashboard</a> to assign displays.</p>';
    }
?>
</div>

<h3 id="awardname"></h3>

<h3 id="classname"></h3>
<h3 id="rankname"></h3>

<h3 id="recipient"></h3>
<p id="carnumber" class="detail"></p>
<p id="carname" class="detail"></p>

<div class="presenter-inner hidden">
  <input type="checkbox" data-role="flipswitch"
        id="reveal-checkbox"
        data-on-text="Showing"
        data-off-text="Hidden"
        onchange="on_reveal();"/>
</div>

<div class="reset-footer block_buttons">
    <input type="button" data-enhanced="true" value="Clear" onclick="on_clear_awards()"/>
</div>

</div>

</div>
</body>
</html>
