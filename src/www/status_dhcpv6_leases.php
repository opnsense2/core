<?php

/*
 * Copyright (C) 2014-2021 Deciso B.V.
 * Copyright (C) 2004-2009 Scott Ullrich <sullrich@gmail.com>
 * Copyright (C) 2011 Seth Mos <seth.mos@dds.nl>
 * Copyright (C) 2003-2004 Manuel Kasper <mk@neon1.net>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

require_once("guiconfig.inc");
require_once("interfaces.inc");
require_once("plugins.inc.d/dhcpd.inc");

function adjust_utc($dt)
{
    foreach (config_read_array('dhcpdv6') as $dhcpdv6) {
        if (!empty($dhcpdv6['dhcpv6leaseinlocaltime'])) {
            /* we want local time, so specify this is actually UTC */
            return strftime('%Y/%m/%d %H:%M:%S', strtotime("{$dt} UTC"));
        }
    }

    /* lease time is in UTC, here just pretend it's the correct time */
    return strftime('%Y/%m/%d %H:%M:%S UTC', strtotime($dt));
}

function remove_duplicate($array, $field)
{
    foreach ($array as $sub) {
        $cmp[] = $sub[$field];
    }
    $unique = array_unique(array_reverse($cmp,true));
    foreach ($unique as $k => $rien) {
        $new[] = $array[$k];
    }
    return $new;
}

$interfaces = legacy_config_get_interfaces(array('virtual' => false));
$order = 'ip';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $leases_content = dhcpd_leases(6);
    $leases_count = count($leases_content);

    exec("/usr/sbin/ndp -an", $rawdata);
    $ndpdata = array();
    foreach ($rawdata as $line) {
        $elements = preg_split('/\s+/ ',$line);
        if ($elements[1] != "(incomplete)") {
            $ndpent = array();
            $ip = trim(str_replace(array('(',')'),'',$elements[0]));
            $ndpent['mac'] = trim($elements[1]);
            $ndpent['interface'] = trim($elements[2]);
            $ndpdata[$ip] = $ndpent;
        }
    }

    $pools = [];
    $leases = [];
    $prefixes = [];
    $mappings = [];
    $i = 0;
    $l = 0;
    $p = 0;

    while($i < $leases_count) {
        $is_prefix = false;
        $duid_split = [];
        $entry = [];

        preg_match('/ia-.. "(.*)" { (.*)/ ', $leases_content[$i], $duid_split);
        if (!empty($duid_split[1])) {
            $iaid_duid = dhcpd_parse_duid($duid_split[1]);
            $entry['iaid'] = hexdec(implode('', array_reverse($iaid_duid[0])));
            $entry['duid'] = implode(':', $iaid_duid[1]);
            $data = explode(' ', $duid_split[2]);
        } else {
            $data = explode(' ', $leases_content[$i]);
        }

        $f = 0;
        $fcount = count($data);

        /* with less than 12 fields there is nothing useful */
        if ($fcount < 12) {
            $i++;
            continue;
        }
        while($f < $fcount) {
            switch($data[$f]) {
                case "failover":
                    $pools[$p]['name'] = $data[$f+2];
                    $pools[$p]['mystate'] = $data[$f+7];
                    $pools[$p]['peerstate'] = $data[$f+14];
                    $pools[$p]['mydate'] = $data[$f+10];
                    $pools[$p]['mydate'] .= " " . $data[$f+11];
                    $pools[$p]['peerdate'] = $data[$f+17];
                    $pools[$p]['peerdate'] .= " " . $data[$f+18];
                    $p++;
                    $i++;
                    continue 3;
                case "ia-pd":
                    $is_prefix = true;
                    /* FALLTHROUGH */
                case "ia-na":
                    if ($data[$f+1][0] == '"') {
                        $duid = "";
                        /* FIXME: This needs a safety belt to prevent an infinite loop */
                        while ($data[$f][strlen($data[$f])-1] != '"') {
                            $duid .= " " . $data[$f+1];
                            $f++;
                        }
                        $entry['duid'] = $duid;
                    } else {
                        $entry['duid'] = $data[$f+1];
                    }
                    $entry['type'] = "dynamic";
                    $f = $f+2;
                    break;
                case "iaaddr":
                    $entry['ip'] = $data[$f+1];
                    $entry['type'] = "dynamic";
                    if (in_array($entry['ip'], array_keys($ndpdata))) {
                        $entry['online'] = 'online';
                    } else {
                        $entry['online'] = 'offline';
                    }
                    $f = $f+2;
                    break;
                case "iaprefix":
                    $is_prefix = true;
                    $entry['prefix'] = $data[$f+1];
                    $entry['type'] = "dynamic";
                    $f = $f+2;
                    break;
                case "starts":
                    $entry['start'] = $data[$f+2];
                    $entry['start'] .= " " . $data[$f+3];
                    $f = $f+3;
                    break;
                case "ends":
                    $entry['end'] = $data[$f+2];
                    $entry['end'] .= " " . $data[$f+3];
                    $f = $f+3;
                    break;
                case "tstp":
                    $f = $f+3;
                    break;
                case "tsfp":
                    $f = $f+3;
                    break;
                case "atsfp":
                    $f = $f+3;
                    break;
                case "cltt":
                    $entry['start'] = $data[$f+2];
                    $entry['start'] .= " " . $data[$f+3];
                    $f = $f+3;
                    break;
                case "binding":
                    switch($data[$f+2]) {
                        case "active":
                            $entry['act'] = "active";
                            break;
                        case "free":
                            $entry['act'] = "expired";
                            $entry['online'] = "offline";
                            break;
                        case "backup":
                            $entry['act'] = "reserved";
                            $entry['online'] = "offline";
                            break;
                        case "released":
                            $entry['act'] = "released";
                            $entry['online'] = "offline";
                    }
                    $f = $f+1;
                    break;
                case "next":
                    /* skip the next binding statement */
                    $f = $f+3;
                    break;
                case "hardware":
                    $f = $f+2;
                    break;
                case "client-hostname":
                    if ($data[$f+1] != '') {
                        $entry['hostname'] = preg_replace('/"/','',$data[$f+1]);
                    } else {
                        $hostname = gethostbyaddr($entry['ip']);
                        if ($hostname != '') {
                            $entry['hostname'] = $hostname;
                        }
                    }
                    $f = $f+1;
                    break;
                case "uid":
                    $f = $f+1;
                    break;
            }
            $f++;
        }
        if ($is_prefix) {
            $prefixes[] = $entry;
        } else {
            $leases[] = $entry;
            $mappings[$entry['iaid'] . $entry['duid']] = $entry['ip'];
        }
        $l++;
        $i++;
    }

    if (count($leases) > 0) {
        $leases = remove_duplicate($leases,"ip");
    }

    if (count($prefixes) > 0) {
        $prefixes = remove_duplicate($prefixes,"prefix");
    }

    if (count($pools) > 0) {
        $pools = remove_duplicate($pools,"name");
        asort($pools);
    }

    $duids = [];
    foreach ($leases as $i => $this_lease) {
        if (!empty($this_lease['duid'])) {
            if (!isset($duids[$this_lease['duid']])) {
                $duids[$this_lease['duid']] = [];
            }
            $duids[$this_lease['duid']][] = $i;
        }
    }

    foreach (dhcpd_staticmap("not.found", legacy_interfaces_details(), false, 6) as $static) {
        $slease = [];
        $slease['ip'] = $static['ipaddrv6'];
        $slease['if'] = $static['interface'];
        $slease['type'] = 'static';
        $slease['duid'] = $static['duid'];
        $slease['start'] = '';
        $slease['end'] = '';
        $slease['hostname'] = $static['hostname'];
        $slease['descr'] = $static['descr'];
        $slease['act'] = 'static';
        $slease['online'] = in_array($slease['ip'], array_keys($ndpdata)) ? 'online' : 'offline';

        if (isset($duids[$slease['duid']])) {
            /* update lease with static data */
            foreach ($slease as $key => $value) {
                if (!empty($value)) {
                    foreach ($duids[$slease['duid']] as $idx) {
                        $leases[$idx][$key] = $value;
                    }
                }
            }
        } else {
            $leases[] = $slease;
        }
    }

    if (isset($_GET['order']) && in_array($_GET['order'], ['int', 'ip', 'iaid', 'duid', 'hostname', 'descr', 'start', 'end', 'online', 'act'])) {
        $order = $_GET['order'];
    }

    usort($leases,
        function ($a, $b) use ($order) {
            $cmp = ($order === 'ip') ? 0 : strnatcasecmp($a[$order] ?? '', $b[$order] ?? '');
            if ($cmp === 0) {
                $cmp = ipcmp($a['ip'], $b['ip']);
            }
            return $cmp;
        }
    );
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['deleteip']) && is_ipaddr($_POST['deleteip'])) {
        killbypid('/var/dhcpd/var/run/dhcpdv6.pid');
        $leasesfile = '/var/dhcpd/var/db/dhcpd6.leases'; /* XXX needs wrapper */
        $fin = @fopen($leasesfile, "r");
        $fout = @fopen($leasesfile.".new", "w");
        if ($fin) {
            $ip_to_remove = $_POST['deleteip'];
            $iaaddr = "";
            $content_to_flush = array();
            while (($line = fgets($fin, 4096)) !== false) {
                $fields = explode(' ', trim($line));
                if ($fields[0] == 'iaaddr') {
                    // lease segment, record ip
                    $iaaddr = trim($fields[1]);
                    $content_to_flush[] = $line;
                } elseif ($fields[0] == 'ia-na' || count($content_to_flush) > 0) {
                    $content_to_flush[] = $line;
                } else {
                    // output data directly if we're not in a "ia-na" section
                    fputs($fout, $line);
                }

                if ($line == "}\n") {
                    if ($iaaddr != $ip_to_remove) {
                        // write ia-na section
                        foreach ($content_to_flush as $cached_line) {
                            fputs($fout, $cached_line);
                        }
                    } else {
                        // skip empty line
                        fgets($fin, 4096);
                    }
                    // end of segment
                    $content_to_flush = array();
                    $iaaddr = "";
                }
            }
            fclose($fin);
            fclose($fout);
            @unlink($leasesfile);
            @rename($leasesfile.".new", $leasesfile);

            dhcpd_dhcp6_configure();
        }
    }
    exit;
}

$service_hook = 'dhcpd6';

include("head.inc");

$leases_count = 0;

foreach ($leases as $data) {
   if (!($data['act'] == 'active' || $data['act'] == 'static' || $_GET['all'] == 1)) {
       continue;
   }
   $leases_count++;
}

$gentitle_suffix = " ($leases_count)";
legacy_html_escape_form_data($leases);
?>
<body>
  <script>
  $( document ).ready(function() {
      $(".act_delete").click(function(){
          $.post(window.location, {deleteip: $(this).data('deleteip')}, function(data) {
              location.reload();
          });
      });
      // keep sorting in place.
      $(".act_sort").click(function(){
          var all = <?=!empty($_GET['all']) ? 1 : 0;?> ;
          document.location = document.location.origin + window.location.pathname +"?all="+all+"&order="+$(this).data('field');
      });
      $(".act_sort :first-child").css('cursor', 'pointer');
  });
  </script>
<?php include("fbegin.inc"); ?>

<section class="page-content-main">
  <div class="container-fluid">
    <div class="row">

<?php
/* only print pool status when we have one */
if (count($pools) > 0):?>
      <section class="col-xs-12">
        <div class="content-box">
          <div class="table-responsive">
              <table class="table table-striped sortable __nomb">
              <tr>
                <td><?=gettext("Failover Group"); ?></a></td>
                <td><?=gettext("My State"); ?></a></td>
                <td><?=gettext("Since"); ?></a></td>
                <td><?=gettext("Peer State"); ?></a></td>
                <td><?=gettext("Since"); ?></a></td>
              </tr>
<?php
              foreach ($pools as $data):?>
              <tr>
                  <td><?=$data['name'];?></td>
                  <td><?=$data['mystate'];?></td>
                  <td><?=adjust_utc($data['mydate']);?></td>
                  <td><?=$data['peerstate'];?></td>
                  <td><?=adjust_utc($data['peerdate']);?></td>
              </tr>

<?php
              endforeach;?>
            </table>
          </div>
        </div>
      </section>
<?php
endif;?>
      <section class="col-xs-12">
        <div class="content-box">
          <div class="table-responsive">
            <table class="table table-striped">
              <thead>
                <tr>
                    <th class="act_sort" data-field="int"><span><?=gettext("Interface"); ?></span></th>
                    <th class="act_sort" data-field="ip"><span><?=gettext("IPv6 address"); ?></span></th>
                    <th class="act_sort" data-field="iaid"><span><?=gettext("IAID"); ?></span></th>
                    <th class="act_sort" data-field="duid"><span><?=gettext("DUID/MAC"); ?></span></th>
                    <th class="act_sort" data-field="hostname"><span><?=gettext("Hostname"); ?></span></th>
                    <th class="act_sort" data-field="descr"><span><?=gettext("Description"); ?></span></th>
                    <th class="act_sort" data-field="start"><span><?=gettext("Start"); ?></span></th>
                    <th class="act_sort" data-field="end"><span><?=gettext("End"); ?></span></th>
                    <th class="act_sort" data-field="online"><span><?=gettext("Online"); ?></span></th>
                    <th class="act_sort" data-field="act"><span><?=gettext("Lease Type"); ?></span></th>
                    <th class="text-nowrap"></th>
                </tr>
              </thead>
              <tbody>
<?php
              $mac_man = json_decode(configd_run("interface list macdb json"), true);
              foreach ($leases as $data):
                if (!($data['act'] == 'active' || $data['act'] == 'static' || $_GET['all'] == 1)) {
                    continue;
                }
                if (!isset($data['if'])) {
                    $data['if'] = convert_real_interface_to_friendly_interface_name(guess_interface_from_ip($data['ip']));
                }
                $data['int'] = htmlspecialchars($interfaces[$data['if']]['descr']);

                $mac_from_ndp = !empty($ndpdata[$data['ip']]) ? $ndpdata[$data['ip']]['mac'] : "";
                $vendor_from_ndp = empty($mac_from_ndp) ? "" : ($mac_man[strtoupper(implode("", explode(":", substr($mac_from_ndp, 0, 8))))] ?? "");

                $mac_from_duid = "";
                $duid_formatted = $data['duid'];
                $duid_type = substr($data['duid'], 0, 5);
                if ($duid_type === "00:01" || $duid_type === "00:03"){
                    $duid_subtype = substr($data['duid'], 6, 5);
                    if ($duid_subtype === "00:01") {
                        $mac_from_duid = substr($data['duid'], -17, 17);
                        $duid_formatted = substr($data['duid'], 0, strlen($data['duid']) - 17) . '<u>' . $mac_from_duid . '</u>';
                    }
                }
                $vendor_from_duid = empty($mac_from_duid) ? "" : ($mac_man[strtoupper(implode("", explode(":", substr($mac_from_duid, 0, 8))))] ?? "");

                $duid_content = $duid_formatted;
                if (!empty($vendor_from_duid)) {
                    $duid_content .= '<br/><small><i>'.$vendor_from_duid.'</i></small>';
                }
                if (!empty($mac_from_ndp) && $mac_from_duid !== $mac_from_ndp) {
                    $duid_content .= '</br>'.gettext('NDP MAC').': '.$mac_from_ndp;
                    if (!empty($vendor_from_ndp)) {
                        $duid_content .= '<br/><small><i>'.$vendor_from_ndp.'</i></small>';
                    }
                }
                ?>
                <tr>
                  <td><?=$data['int'];?></td>
                  <td><?=$data['ip'];?></td>
                  <td><?=$data['iaid'];?></td>
                  <td><?=$duid_content;?></td>
                  <td><?= !empty($data['hostname']) ? html_safe($data['hostname']) : '' ?></td>
                  <td><?= html_safe($data['descr'] ?? '');?></td>
                  <td><?= !empty($data['start']) ? adjust_utc($data['start']) : '' ?></td>
                  <td><?= !empty($data['end']) ? adjust_utc($data['end']) : '' ?></td>
                  <td>
                    <i class="fa fa-<?=$data['online']=='online' ? 'signal' : 'ban';?>" title="<?=$data['online'];?>" data-toggle="tooltip"></i>
                  </td>
                  <td><?=$data['act'];?></td>
                  <td class="text-nowrap">
<?php if (!empty($config['interfaces'][$data['if']])): ?>
<?php if (empty($config['interfaces'][$data['if']]['virtual']) && isset($config['interfaces'][$data['if']]['enable'])): ?>
<?php if (is_ipaddrv6($config['interfaces'][$data['if']]['ipaddrv6']) || !empty($config['interfaces'][$data['if']]['dhcpd6track6allowoverride'])): ?>
<?php if ($data['type'] == 'dynamic'): ?>
                        <a class="btn btn-default btn-xs" href="services_dhcpv6_edit.php?if=<?=$data['if'];?>&amp;duid=<?=$data['duid'];?>&amp;hostname=<?=$data['hostname'];?>">
                          <i class="fa fa-plus fa-fw"></i>
                        </a>
<?php if ($data['online'] != 'online'): ?>
                    <a class="act_delete btn btn-default btn-xs" href="#" data-deleteip="<?=$data['ip'];?>" title="<?= html_safe(gettext('Delete')) ?>" data-toggle="tooltip">
                      <i class="fa fa-trash fa-fw"></i>
                    </a>
<?php endif ?>
<?php endif ?>
<?php endif ?>
<?php endif ?>
<?php endif ?>
                  </td>
                </tr>
<?php
                endforeach;?>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section class="col-xs-12">
        <div class="content-box">
          <header class="content-box-head container-fluid">
           <h3><?=gettext("Delegated Prefixes");?></h3>
          </header>
          <div class="table-responsive">
            <table class="table table-striped">
              <thead>
                <tr>
                  <th><?=gettext("IPv6 Prefix"); ?></th>
                  <th><?=gettext("IAID"); ?></th>
                  <th><?=gettext("DUID"); ?></th>
                  <th><?=gettext("Start"); ?></th>
                  <th><?=gettext("End"); ?></th>
                  <th><?=gettext("State"); ?></th>
                </tr>
              </thead>
              <tbody>
<?php
                foreach ($prefixes as $data):?>
                <tr>
                  <td>
                    <?=!empty($mappings[$data['iaid'] . $data['duid']]) ? $mappings[$data['iaid'] . $data['duid']] : "";?>
                    <?=$data['prefix'];?>
                  </td>
                  <td><?=$data['iaid'];?></td>
                  <td><?=$data['duid'];?></td>
                  <td><?= !empty($data['start']) ? adjust_utc($data['start']) : '' ?></td>
                  <td><?= !empty($data['end']) ? adjust_utc($data['end']) : '' ?></td>
                  <td><?=$data['act'];?></td>
                </tr>
<?php
                  endforeach;?>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <section class="col-xs-12">
          <form method="get">
          <input type="hidden" name="order" value="<?= html_safe($order) ?>" />
          <?php if ($_GET['all'] ?? 0): ?>
          <input type="hidden" name="all" value="0" />
          <input type="submit" class="btn btn-default" value="<?= html_safe(gettext('Show active and static leases only')) ?>" />
          <?php else: ?>
          <input type="hidden" name="all" value="1" />
          <input type="submit" class="btn btn-default" value="<?= html_safe(gettext('Show all configured leases')) ?>" />
          <?php endif; ?>
          </form>
          <?php if ($leases == 0): ?>
          <p><?= gettext('No leases file found. Is the DHCP server active?') ?></p>
          <?php endif; ?>
      </section>
    </div>
  </div>
</section>
<?php

include("foot.inc");
