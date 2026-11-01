<?php
/* Weekly display schedule, evaluated only on the Unraid host. GPL-3.0-or-later. */
declare(strict_types=1);

function td_host_timezone(): DateTimeZone {
    // Unraid's native local_prepend.php also derives time from /etc/localtime.
    // Resolve it for CLI sampling as well, without changing global PHP state.
    $link=@readlink(root_path('/etc/localtime'));
    $vars=@parse_ini_file(root_path('/var/local/emhttp/var.ini'),false,INI_SCANNER_RAW) ?: [];
    $names=[];
    if (is_string($link) && preg_match('~(?:^|/)zoneinfo/(?:posix/|right/)?(.+)$~D',$link,$match)) $names[]=$match[1];
    $names[]=$vars['timeZone']??'';
    $names[]=date_default_timezone_get();
    foreach ($names as $name) {
        if (!is_string($name) || $name==='' || strlen($name)>128) continue;
        try { return new DateTimeZone($name); } catch (Throwable $e) { continue; }
    }
    return new DateTimeZone('UTC');
}

function td_display_state(array $quiet,?DateTimeImmutable $now=null): array {
    $now=$now??new DateTimeImmutable('now',td_host_timezone());
    $off=false;
    if ($quiet['enabled']) {
        $day=(int)$now->format('N');
        $minute=(int)$now->format('H')*60+(int)$now->format('i');
        $start=(int)substr($quiet['start'],0,2)*60+(int)substr($quiet['start'],3,2);
        $end=(int)substr($quiet['end'],0,2)*60+(int)substr($quiet['end'],3,2);
        $today=in_array($day,$quiet['days'],true);
        if ($quiet['all_day']) $off=$today;
        elseif ($start<$end) $off=$today && $minute>=$start && $minute<$end;
        else {
            // A selected day starts the interval. Monday 23:00–07:00 also
            // includes Tuesday before 07:00, including across DST changes.
            $previous=$day===1?7:$day-1;
            $off=($today && $minute>=$start) || ($minute<$end && in_array($previous,$quiet['days'],true));
        }
    }
    return ['off'=>$off,'reason'=>$off?'schedule':null,'timezone'=>$now->getTimezone()->getName(),'local_time'=>$now->format('D Y-m-d H:i:s')];
}
