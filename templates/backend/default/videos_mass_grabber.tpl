<!-- Mass Video Grabber - Main Template -->
<link href="{$config['BASE_URL']}/media/player/videojs/video-js.css" rel="stylesheet">
<script src="{$config['BASE_URL']}/media/player/videojs/video.js"></script>
<div class="page-content">
<div class="content">
<div class="page-title">
    <i class="icon-custom-left"></i>
    <h3>Videos - <span class="semi-bold">Mass Video Grabber</span></h3>
</div>
{include file="errmsg.tpl"}

{if $mg_needs_install}
<div class="grid simple">
    <div class="grid-title no-border">
        <h4><i class="fa fa-exclamation-triangle text-warning"></i> Installation Required</h4>
    </div>
    <div class="grid-body no-border">
        <div class="alert alert-warning">
            <strong>Database tables not found.</strong><br>
            Please run the following SQL file against your database to install the Mass Video Grabber:
        </div>
        <div class="well" style="background:#1a1a2e;color:#e0e0e0;font-family:monospace;font-size:13px;padding:15px;white-space:pre-wrap;overflow-x:auto;">sql/install_mass_grabber.sql</div>
        <p class="text-muted" style="font-size:12px;margin-top:10px;">
            <strong>MySQL CLI:</strong> <code>mysql -u root -p avs &lt; sql/install_mass_grabber.sql</code><br>
            <strong>phpMyAdmin:</strong> Import the file via the Import tab.
        </p>
        <hr>
        <p>After running the SQL, <a href="videos.php?m=mass_grabber" class="btn btn-sm btn-primary"><i class="fa fa-refresh"></i> Refresh this page</a>.</p>
    </div>
</div>
{/if}

{literal}
<style>
.mg-stat-box{background:#fff;border:1px solid #e5e5e5;border-radius:4px;padding:18px 15px;text-align:center;margin-bottom:15px}
.mg-stat-box .mg-stat-num{font-size:28px;font-weight:700;line-height:1.2}
.mg-stat-box .mg-stat-label{font-size:12px;color:#888;margin-top:4px}
.mg-stat-box.mg-stat-primary .mg-stat-num{color:#3bafda}
.mg-stat-box.mg-stat-success .mg-stat-num{color:#8cc152}
.mg-stat-box.mg-stat-warning .mg-stat-num{color:#f6bb42}
.mg-stat-box.mg-stat-danger .mg-stat-num{color:#da4453}
.mg-stat-box.mg-stat-info .mg-stat-num{color:#967adc}
.mg-nav-tabs{margin-bottom:15px}
.mg-nav-tabs .nav-tabs{border-bottom:2px solid #e5e5e5}
.mg-nav-tabs .nav-tabs>li>a{font-weight:600;font-size:13px;padding:10px 18px;color:#666}
.mg-nav-tabs .nav-tabs>li.active>a,.mg-nav-tabs .nav-tabs>li.active>a:hover,.mg-nav-tabs .nav-tabs>li.active>a:focus{border-color:#e5e5e5 #e5e5e5 #fff;color:#333;border-bottom:2px solid #fff;margin-bottom:-2px}
.mg-table th{font-size:12px;text-transform:uppercase;color:#888;font-weight:600}
.mg-table td{vertical-align:middle!important}
.mg-thumb-sm{width:60px;height:40px;object-fit:cover;border-radius:3px;border:1px solid #eee}
.mg-mini-player{position:relative;width:200px;height:112px;border-radius:4px;overflow:hidden;border:1px solid #ddd;background:#000}
.mg-mini-player img{width:100%;height:100%;object-fit:cover}
.mg-mini-player .mg-mini-overlay{position:absolute;top:0;left:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:2}
.mg-mini-player .mg-mini-overlay .mg-mini-play{color:#fff;font-size:26px;text-shadow:0 1px 4px rgba(0,0,0,.8);transition:opacity .2s}
.mg-mini-player:hover .mg-mini-overlay .mg-mini-play{opacity:.7}
.mg-mini-player iframe{position:absolute;top:0;left:0;width:100%;height:100%;border:0;z-index:1}
.mg-mini-player iframe[src=""]{display:none}
.mg-title-link{cursor:pointer;color:#333;text-decoration:none}
.mg-title-link:hover{color:#3bafda;text-decoration:underline}
.mg-status{display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600}
.mg-status-new{background:#e8f5e9;color:#2e7d32}
.mg-status-exists{background:#fff3e0;color:#e65100}
.mg-status-queued{background:#e3f2fd;color:#1565c0}
.mg-status-processing{background:#ede7f6;color:#4527a0}
.mg-status-imported{background:#e0f7fa;color:#00695c}
.mg-status-failed{background:#fce4ec;color:#c62828}
.mg-status-skipped{background:#f5f5f5;color:#757575}
.mg-job-pending{background:#fff3e0;color:#e65100}
.mg-job-processing{background:#e3f2fd;color:#1565c0}
.mg-job-completed{background:#e8f5e9;color:#2e7d32}
.mg-job-failed{background:#fce4ec;color:#c62828}
.mg-job-cancelled{background:#f5f5f5;color:#757575}
.mg-sel-chip{display:inline-block;background:#eef4fb;border:1px solid #cfddee;border-radius:12px;padding:1px 9px;font-size:11px;color:#33475b;margin:2px 5px 2px 0;max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;vertical-align:middle}
.mg-sel-chip a.mg-sel-x{color:#8fa6bd;font-weight:700;margin-left:5px;text-decoration:none;cursor:pointer}
.mg-sel-chip a.mg-sel-x:hover{color:#d9534f}
.mg-sel-chip small{color:#8fa6bd;margin-left:3px}
.mg-loading{display:none;text-align:center;padding:40px;font-size:14px;color:#888}
.mg-loading i{font-size:24px;display:block;margin-bottom:10px}
.mg-cap-yes{color:#8cc152}
.mg-cap-no{color:#ccc}
.mg-source-card{border:1px solid #e5e5e5;border-radius:4px;padding:15px;margin-bottom:10px;background:#fff}
.mg-source-card h5{margin:0 0 5px;font-weight:600}
.mg-source-card .mg-source-meta{font-size:12px;color:#888}
.mg-discover-progress{display:none;padding:20px;text-align:center}
.mg-discover-progress .mg-progress-text{font-size:14px;margin-top:10px}
#mg_disc_filters .btn{margin-right:2px}
#mg_disc_filters .btn.active{font-weight:600}
#mg_disc_url_preview{font-size:11px;color:#999;word-break:break-all}
#mg_disc_timeframe .btn, #mg_disc_sort .btn{font-size:12px;padding:4px 10px}
#mg_disc_timeframe .btn.active, #mg_disc_sort .btn.active{font-weight:600;background:#3bafda;color:#fff;border-color:#3bafda}
#btn_realtime{transition:all .2s}
#btn_realtime.btn-danger{background:#da4453;color:#fff;border-color:#da4453}
#btn_realtime.btn-danger:hover{background:#c0392b;border-color:#c0392b}
</style>
{/literal}

<!-- NAVIGATION TABS -->
<div class="mg-nav-tabs">
    <ul class="nav nav-tabs" id="mg_tabs">
        <li{if $view == 'dashboard' || $view == ''} class="active"{/if}><a href="videos.php?m=mass_grabber&v=dashboard"><i class="fa fa-tachometer"></i> Dashboard</a></li>
        <li{if $view == 'sources'} class="active"{/if}><a href="videos.php?m=mass_grabber&v=sources"><i class="fa fa-database"></i> Sources</a></li>
        <li{if $view == 'discover'} class="active"{/if}><a href="videos.php?m=mass_grabber&v=discover"><i class="fa fa-search"></i> Discover</a></li>
        <li{if $view == 'queue'} class="active"{/if}><a href="videos.php?m=mass_grabber&v=queue"><i class="fa fa-list"></i> Queue</a></li>
        <li{if $view == 'history'} class="active"{/if}><a href="videos.php?m=mass_grabber&v=history"><i class="fa fa-history"></i> History</a></li>
    </ul>
</div>

{if $view == 'dashboard' || $view == ''}
<div id="mg-dashboard">
    <div class="row">
        <div class="col-xs-6 col-sm-4 col-md-3"><div class="mg-stat-box mg-stat-primary"><div class="mg-stat-num">{$stats.sources}</div><div class="mg-stat-label">Active Sources</div></div></div>
        <div class="col-xs-6 col-sm-4 col-md-3"><div class="mg-stat-box mg-stat-info"><div class="mg-stat-num">{$stats.discovered}</div><div class="mg-stat-label">Total Discovered</div></div></div>
        <div class="col-xs-6 col-sm-4 col-md-3"><div class="mg-stat-box mg-stat-success"><div class="mg-stat-num">{$stats.imported}</div><div class="mg-stat-label">Imported</div></div></div>
        <div class="col-xs-6 col-sm-4 col-md-3"><div class="mg-stat-box"><div class="mg-stat-num">{$stats.new_videos}</div><div class="mg-stat-label">New (Pending)</div></div></div>
        <div class="col-xs-6 col-sm-4 col-md-3"><div class="mg-stat-box mg-stat-warning"><div class="mg-stat-num">{$stats.queued}</div><div class="mg-stat-label">In Queue</div></div></div>
        <div class="col-xs-6 col-sm-4 col-md-3"><div class="mg-stat-box"><div class="mg-stat-num">{$stats.processing}</div><div class="mg-stat-label">Processing</div></div></div>
        <div class="col-xs-6 col-sm-4 col-md-3"><div class="mg-stat-box mg-stat-danger"><div class="mg-stat-num">{$stats.failed}</div><div class="mg-stat-label">Failed</div></div></div>
        <div class="col-xs-6 col-sm-4 col-md-3"><div class="mg-stat-box"><div class="mg-stat-num">{$stats.last_run_text}</div><div class="mg-stat-label">Last Run</div></div></div>
    </div>
    <div class="grid simple" style="margin-top:10px">
        <div class="grid-title no-border"><h4>Scheduler <span class="semi-bold">Status</span></h4></div>
        <div class="grid-body no-border">
            <div class="row">
                <div class="col-sm-4"><strong>Active Sources:</strong> {$scheduler_status.active_sources}</div>
                <div class="col-sm-4"><strong>Next Run:</strong> {$scheduler_status.next_source_text}</div>
                <div class="col-sm-4"><strong>Pending Jobs:</strong> {$scheduler_status.pending_jobs} &nbsp;|&nbsp; <strong>Running:</strong> {$scheduler_status.running_jobs}</div>
            </div>
            <hr style="margin:10px 0">
            <button class="btn btn-sm btn-primary" id="btn_run_scheduler" onclick="mgRunScheduler();"><i class="fa fa-refresh"></i> Run Scheduler Now</button>
            <span id="scheduler_result" class="m-l-10" style="display:none"></span>
        </div>
    </div>
    <div class="grid simple" style="margin-top:10px">
        <div class="grid-title no-border"><h4>Recent <span class="semi-bold">Runs</span></h4></div>
        <div class="grid-body no-border"><div id="mg_dashboard_runs"><p class="text-muted">Loading recent runs...</p></div></div>
    </div>
</div>
{/if}

{if $view == 'sources'}
<div id="mg-sources">
    <div class="grid simple">
        <div class="grid-title no-border">
            <h4>Sources <span class="semi-bold">Management</span></h4>
            <button class="btn btn-success btn-sm pull-right" onclick="mgShowSourceForm();"><i class="fa fa-plus"></i> Add Source</button>
            <div class="clearfix"></div>
        </div>
        <div class="grid-body no-border">
            {if $sources|@count > 0}
            <table class="table mg-table">
                <thead><tr><th>Name</th><th>Provider</th><th>URL</th><th>Auto</th><th>Schedule</th><th>Last Run</th><th>Health</th><th>Actions</th></tr></thead>
                <tbody>
                    {section name=i loop=$sources}
                    <tr>
                        <td><strong>{$sources[i].name|escape:'html'}</strong></td>
                        <td><span class="label label-info">{$sources[i].provider|escape:'html'}</span></td>
                        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{$sources[i].discovery_url|escape:'html'}">{$sources[i].discovery_url|escape:'html'}</td>
                        <td>{if $sources[i].automatic_enabled}<span class="text-success"><i class="fa fa-check"></i> ON</span>{else}<span class="text-muted"><i class="fa fa-times"></i> OFF</span>{/if}</td>
                        <td>{$sources[i].schedule_type} {if $sources[i].schedule_type == 'daily'}@ {$sources[i].schedule_value}{/if}</td>
                        <td>{$sources[i].last_run_ago}</td>
                        <td>{if $sources[i].health_status == 'HEALTHY'}<span class="mg-status" style="background:#e8f5e9;color:#2e7d32">Healthy</span>{elseif $sources[i].health_status == 'WARNING'}<span class="mg-status" style="background:#fff3e0;color:#e65100">Warning</span>{elseif $sources[i].health_status == 'FAILED'}<span class="mg-status mg-status-failed">Failed</span>{else}<span class="mg-status mg-status-skipped">Disabled</span>{/if}</td>
                        <td>
                            <div class="btn-group btn-group-xs">
                                <button class="btn btn-primary" onclick="mgEditSource({$sources[i].id})" title="Edit"><i class="fa fa-pencil"></i></button>
                                <button class="btn btn-info" onclick="mgGoToDiscover({$sources[i].id})" title="Discover"><i class="fa fa-search"></i></button>
                                <button class="btn btn-warning" onclick="mgToggleSource({$sources[i].id})" title="Toggle">{if $sources[i].enabled}<i class="fa fa-pause"></i>{else}<i class="fa fa-play"></i>{/if}</button>
                                <button class="btn btn-danger" onclick="mgDeleteSource({$sources[i].id}, '{$sources[i].name|escape:'javascript'}')" title="Delete"><i class="fa fa-trash-o"></i></button>
                            </div>
                        </td>
                    </tr>
                    {/section}
                </tbody>
            </table>
            {else}
            <div class="alert alert-info"><i class="fa fa-info-circle"></i> No sources configured yet. Click "Add Source" to get started.</div>
            {/if}
        </div>
    </div>
    <div class="grid simple">
        <div class="grid-title no-border"><h4>Supported <span class="semi-bold">Providers</span></h4></div>
        <div class="grid-body no-border">
            <table class="table mg-table">
                <thead><tr><th>Provider</th><th>Grab</th><th>Metadata</th><th>Discovery</th><th>Versions</th></tr></thead>
                <tbody>
                    {section name=i loop=$providers}
                    <tr>
                        <td><strong>{$providers[i].name|escape:'html'}</strong></td>
                        <td>{if in_array('Grab', $providers[i].capabilities)}<i class="fa fa-check mg-cap-yes"></i>{else}<i class="fa fa-times mg-cap-no"></i>{/if}</td>
                        <td>{if in_array('Metadata', $providers[i].capabilities)}<i class="fa fa-check mg-cap-yes"></i>{else}<i class="fa fa-times mg-cap-no"></i>{/if}</td>
                        <td>{if in_array('Discovery', $providers[i].capabilities)}<i class="fa fa-check mg-cap-yes"></i>{else}<i class="fa fa-times mg-cap-no"></i>{/if}</td>
                        <td>{if in_array('Versions', $providers[i].capabilities)}<i class="fa fa-check mg-cap-yes"></i>{else}<i class="fa fa-times mg-cap-no"></i>{/if}</td>
                    </tr>
                    {/section}
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="modal fade" id="mg_source_modal" tabindex="-1" role="dialog" aria-hidden="true" style="display:none">
    <div class="modal-dialog" style="width:650px">
        <div class="modal-content">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button><h4 class="modal-title semi-bold"><i class="fa fa-database"></i> <span id="mg_source_modal_title">Add Source</span></h4></div>
            <div class="modal-body">
                <div id="mg_source_alert" style="display:none"></div>
                <form id="mg_source_form">
                    <input type="hidden" id="mg_src_id" value="">
                    <div class="form-group"><label class="control-label">Name</label><input type="text" class="form-control" id="mg_src_name" placeholder="e.g. XVideos Amateur" required></div>
                    <div class="form-group"><label class="control-label">Provider</label><select class="form-control" id="mg_src_provider">{section name=i loop=$providers}<option value="{$providers[i].name|escape:'html'}">{$providers[i].name|escape:'html'}</option>{/section}</select></div>
                    <div class="form-group"><label class="control-label">Discovery URL</label><input type="url" class="form-control" id="mg_src_url" placeholder="https://www.youtube.com/@channel or listing page URL"><span class="help">The page URL to scan for videos.</span></div>
                    <div class="row">
                        <div class="col-sm-6"><div class="form-group"><label class="control-label">Category</label><select class="form-control" id="mg_src_category"><option value="0">-- Default --</option>{section name=i loop=$categories}<option value="{$categories[i].CHID}">{$categories[i].name|escape:'html'}</option>{/section}</select></div></div>
                        <div class="col-sm-6"><div class="form-group"><label class="control-label">Quality</label><select class="form-control" id="mg_src_quality"><option value="best">Best Available</option><option value="1080">1080p</option><option value="720">720p</option><option value="480">480p</option><option value="360">360p</option></select></div></div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-sm-6"><div class="form-group"><label class="control-label">Automatic Grab</label><select class="form-control" id="mg_src_auto"><option value="0">OFF</option><option value="1">ON</option></select></div></div>
                        <div class="col-sm-6"><div class="form-group"><label class="control-label">Schedule</label><select class="form-control" id="mg_src_schedule_type"><option value="hourly">Hourly</option><option value="daily" selected>Daily</option><option value="weekly">Weekly</option><option value="interval">Custom Interval</option></select></div></div>
                    </div>
                    <div class="row">
                        <div class="col-sm-4"><div class="form-group"><label class="control-label">Schedule Value</label><input type="text" class="form-control" id="mg_src_schedule_value" value="02:00"><span class="help" id="mg_schedule_help">HH:MM for daily</span></div></div>
                        <div class="col-sm-4"><div class="form-group"><label class="control-label">Max per Run</label><input type="number" class="form-control" id="mg_src_max_per_run" value="5" min="1" max="50"></div></div>
                        <div class="col-sm-4"><div class="form-group"><label class="control-label">Discovery Pages</label><input type="number" class="form-control" id="mg_src_max_pages" value="3" min="1" max="20"></div></div>
                    </div>
                    <div class="row">
                        <div class="col-sm-4"><div class="form-group"><label class="control-label">Delay (seconds)</label><input type="number" class="form-control" id="mg_src_delay" value="1" min="0" max="30"></div></div>
                        <div class="col-sm-4"><div class="form-group"><label class="control-label">Discovery Enabled</label><select class="form-control" id="mg_src_discovery"><option value="1">ON</option><option value="0">OFF</option></select></div></div>
                        <div class="col-sm-4"><div class="form-group"><label class="control-label">Enabled</label><select class="form-control" id="mg_src_enabled"><option value="1">ON</option><option value="0">OFF</option></select></div></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default pull-left" data-dismiss="modal">Cancel</button><button type="button" class="btn btn-success" id="btn_save_source" onclick="mgSaveSource()"><i class="fa fa-check"></i> Save</button></div>
        </div>
    </div>
</div>
{/if}

{if $view == 'discover'}
<div id="mg-discover">
    <div class="grid simple">
        <div class="grid-title no-border"><h4>Discover <span class="semi-bold">Videos</span></h4></div>
        <div class="grid-body no-border">
            <div class="row m-b-10">
                <div class="col-sm-3"><label class="control-label">Source</label><select class="form-control" id="mg_disc_source"><option value="0">-- Select Source --</option>{section name=i loop=$sources}<option value="{$sources[i].id}" data-url="{$sources[i].discovery_url|escape:'html'}">{$sources[i].name|escape:'html'}</option>{/section}</select></div>
                <div class="col-sm-7"><label class="control-label">Search</label><div class="input-group"><input type="text" class="form-control" id="mg_disc_query" placeholder="Search videos... (leave empty for all)"><span class="input-group-btn"><button class="btn btn-primary" type="button" id="btn_scan" onclick="mgStartScan()"><i class="fa fa-search"></i> Scan</button></span></div></div>
            </div>
            <div class="row m-b-10">
                <div class="col-sm-12">
                    <div class="btn-group" id="mg_disc_filters">
                        <button class="btn btn-sm btn-default active" data-filter="videos" onclick="mgSetFilter('videos')"><i class="fa fa-play-circle"></i> Videos</button>
                        <button class="btn btn-sm btn-default" data-filter="shorts" onclick="mgSetFilter('shorts')"><i class="fa fa-bolt"></i> Shorts</button>
                        <button class="btn btn-sm btn-default" data-filter="releases" onclick="mgSetFilter('releases')"><i class="fa fa-compact-disc"></i> Releases</button>
                        <button class="btn btn-sm btn-default" data-filter="search" onclick="mgSetFilter('search')"><i class="fa fa-search"></i> Search</button>
                    </div>
                    <span class="text-muted m-l-10" style="font-size:12px" id="mg_disc_url_preview"></span>
                </div>
            </div>
            <div id="mg_disc_status" class="alert" style="display:none"></div>
            <div class="mg-discover-progress" id="mg_disc_progress"><i class="fa fa-spinner fa-spin fa-3x"></i><div class="mg-progress-text">Scanning...</div></div>
        </div>
    </div>
            <div id="mg_disc_results" style="display:none">
        <div class="grid simple">
            <div class="grid-title no-border">
                <h4>Discovery <span class="semi-bold">Results</span> <small id="mg_disc_summary"></small></h4>
                <div class="pull-right"><div class="btn-group btn-group-sm"><button class="btn btn-default active" onclick="mgFilterDiscovered('')">All</button><button class="btn btn-success" onclick="mgFilterDiscovered('NEW')">New</button><button class="btn btn-warning" onclick="mgFilterDiscovered('EXISTS')">Existing</button><button class="btn btn-info" onclick="mgFilterDiscovered('IMPORTED')">Imported</button></div></div>
                <div class="clearfix"></div>
            </div>
            <div class="grid-body no-border" style="padding-top:0">
                <div class="row m-b-10">
                    <div class="col-sm-6">
                        <label class="control-label" style="font-size:12px;color:#888"><i class="fa fa-clock-o"></i> Time Period</label>
                        <div class="btn-group btn-group-sm" id="mg_disc_timeframe">
                            <button class="btn btn-default active" data-timeframe="" onclick="mgSetTimeframe('')">All Time</button>
                            <button class="btn btn-default" data-timeframe="today" onclick="mgSetTimeframe('today')"><i class="fa fa-sun-o"></i> Today</button>
                            <button class="btn btn-default" data-timeframe="week" onclick="mgSetTimeframe('week')"><i class="fa fa-calendar"></i> This Week</button>
                            <button class="btn btn-default" data-timeframe="month" onclick="mgSetTimeframe('month')"><i class="fa fa-calendar-o"></i> This Month</button>
                            <button class="btn btn-default" data-timeframe="3months" onclick="mgSetTimeframe('3months')"><i class="fa fa-calendar-plus-o"></i> 3 Months</button>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <label class="control-label" style="font-size:12px;color:#888"><i class="fa fa-sort"></i> Sort By</label>
                        <div class="btn-group btn-group-sm" id="mg_disc_sort">
                            <button class="btn btn-default active" data-sort="newest" onclick="mgSetSort('newest')"><i class="fa fa-arrow-down"></i> Newest</button>
                            <button class="btn btn-default" data-sort="oldest" onclick="mgSetSort('oldest')"><i class="fa fa-arrow-up"></i> Oldest</button>
                            <button class="btn btn-default" data-sort="duration" onclick="mgSetSort('duration')"><i class="fa fa-clock-o"></i> Duration</button>
                            <button class="btn btn-default" data-sort="title" onclick="mgSetSort('title')"><i class="fa fa-font"></i> Title</button>
                        </div>
                    </div>
                </div>
            <div class="grid-body no-border">
                <div id="mg_disc_bulk_actions" style="display:none;margin-bottom:10px">
                    <div class="row">
                        <div class="col-sm-12">
                            <button class="btn btn-default btn-xs" onclick="mgSelSelectPage()" title="Select every grabbable video on this page"><i class="fa fa-check-square-o"></i> Select this page</button>
                            <button class="btn btn-success btn-xs" onclick="mgSelSelectAllNew()" title="Select all NEW videos found for this source (current time period)"><i class="fa fa-check-square-o"></i> Select All New</button>
                            <button class="btn btn-link btn-xs" id="btn_mg_clear_sel" onclick="mgSelClearAll()" style="display:none;padding-left:4px"><i class="fa fa-times"></i> Clear selection</button>
                            <span class="pull-right" style="line-height:28px">
                                <span class="m-r-10"><strong id="mg_selected_count" style="color:#333">0</strong> <span class="text-muted">selected</span></span>
                                <button class="btn btn-primary" id="btn_bulk_grab" onclick="mgBulkGrab()" disabled><i class="fa fa-cloud-download"></i> GRAB SELECTED</button>
                            </span>
                        </div>
                    </div>
                    <div id="mg_selected_chips" style="display:none;max-height:104px;overflow-y:auto;margin:4px 0 0"></div>
                    <hr style="margin:8px 0 2px">
                </div>
                <div id="mg_disc_video_list"><p class="text-muted">No videos discovered yet. Run a scan above.</p></div>
                <div id="mg_disc_pagination" style="display:none;text-align:center;margin-top:15px">
                    <ul id="mg_disc_pager" class="pagination pagination-sm" style="margin:0;display:inline-flex">
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
{/if}

{if $view == 'queue'}
<div id="mg-queue">
    <div class="row m-b-15">
        <div class="col-xs-6 col-sm-3"><div class="mg-stat-box mg-stat-warning"><div class="mg-stat-num" id="mg_q_pending">{$job_counts.PENDING}</div><div class="mg-stat-label">Pending</div></div></div>
        <div class="col-xs-6 col-sm-3"><div class="mg-stat-box mg-stat-info"><div class="mg-stat-num" id="mg_q_processing">{$job_counts.PROCESSING}</div><div class="mg-stat-label">Processing</div></div></div>
        <div class="col-xs-6 col-sm-3"><div class="mg-stat-box mg-stat-success"><div class="mg-stat-num" id="mg_q_completed">{$job_counts.COMPLETED}</div><div class="mg-stat-label">Completed</div></div></div>
        <div class="col-xs-6 col-sm-3"><div class="mg-stat-box mg-stat-danger"><div class="mg-stat-num" id="mg_q_failed">{$job_counts.FAILED}</div><div class="mg-stat-label">Failed</div></div></div>
    </div>
    <div class="grid simple">
        <div class="grid-title no-border">
            <h4>Grab <span class="semi-bold">Queue</span></h4>
            <div class="pull-right">
                <button class="btn btn-sm" id="btn_realtime" onclick="mgToggleRealtime()" style="margin-right:5px"><i class="fa fa-bolt"></i> Real-time: OFF</button>
                <button class="btn btn-sm btn-success" id="btn_process_now" onclick="mgProcessNow()"><i class="fa fa-play"></i> Process Now</button>
                <button class="btn btn-sm btn-warning" id="btn_pause_all" onclick="mgPauseAll()"><i class="fa fa-pause"></i> Pause All</button>
                <button class="btn btn-sm btn-info" id="btn_resume_all" onclick="mgResumeAll()"><i class="fa fa-play"></i> Resume All</button>
            </div>
            <div class="clearfix"></div>
        </div>
        <div class="grid-body no-border">
            <div class="m-b-10"><div class="btn-group btn-group-sm"><button class="btn btn-default active" onclick="mgFilterJobs('')">All</button><button class="btn btn-warning" onclick="mgFilterJobs('PENDING')">Pending</button><button class="btn btn-info" onclick="mgFilterJobs('PROCESSING')">Processing</button><button class="btn btn-default" onclick="mgFilterJobs('PAUSED')">Paused</button><button class="btn btn-success" onclick="mgFilterJobs('COMPLETED')">Completed</button><button class="btn btn-danger" onclick="mgFilterJobs('FAILED')">Failed</button></div></div>
            <div id="mg_queue_list"><p class="text-muted">Loading jobs...</p></div>
        </div>
    </div>
</div>
{/if}

{if $view == 'history'}
<div id="mg-history">
    <div class="grid simple">
        <div class="grid-title no-border"><h4>Run <span class="semi-bold">History</span></h4></div>
        <div class="grid-body no-border">
            {if $runs|@count > 0}
            <table class="table mg-table">
                <thead><tr><th>#</th><th>Source</th><th>Type</th><th>Status</th><th>Found</th><th>New</th><th>Existing</th><th>Queued</th><th>Imported</th><th>Failed</th><th>Started</th><th>Duration</th><th>Logs</th></tr></thead>
                <tbody>
                    {section name=i loop=$runs}
                    <tr>
                        <td>{$runs[i].id}</td>
                        <td>{$runs[i].source_name|escape:'html'}</td>
                        <td><span class="label label-default">{$runs[i].run_type}</span></td>
                        <td>{if $runs[i].status == 'FINISHED'}<span class="text-success"><i class="fa fa-check"></i> Finished</span>{elseif $runs[i].status == 'RUNNING'}<span class="text-info"><i class="fa fa-spinner fa-spin"></i> Running</span>{else}<span class="text-danger"><i class="fa fa-times"></i> {$runs[i].status}</span>{/if}</td>
                        <td>{$runs[i].found_count}</td>
                        <td><span class="text-success">{$runs[i].new_count}</span></td>
                        <td><span class="text-muted">{$runs[i].existing_count}</span></td>
                        <td><span class="text-info">{$runs[i].queued_count}</span></td>
                        <td>{$runs[i].imported_count}</td>
                        <td>{if $runs[i].failed_count > 0}<span class="text-danger">{$runs[i].failed_count}</span>{else}0{/if}</td>
                        <td>{$runs[i].started_at|date_format:"%d/%m %H:%M"}</td>
                        <td>{$runs[i].duration_text}</td>
                        <td><button class="btn btn-xs btn-default" onclick="mgViewRunLogs({$runs[i].id})"><i class="fa fa-file-text-o"></i></button></td>
                    </tr>
                    {/section}
                </tbody>
            </table>
            {else}
            <div class="alert alert-info"><i class="fa fa-info-circle"></i> No run history yet.</div>
            {/if}
        </div>
    </div>
</div>
{/if}

</div>
</div>

<!-- Log Viewer Modal -->
<div class="modal fade" id="mg_log_modal" tabindex="-1" role="dialog" aria-hidden="true" style="display:none">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button><h4 class="modal-title semi-bold"><i class="fa fa-file-text-o"></i> Run Logs</h4></div>
            <div class="modal-body"><div id="mg_log_content" style="max-height:400px;overflow-y:auto;background:#1a1a2e;color:#e0e0e0;padding:15px;border-radius:4px;font-family:monospace;font-size:12px;white-space:pre-wrap"></div></div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<!-- Video Preview Modal -->
<div class="modal fade" id="mg_preview_modal" tabindex="-1" role="dialog" aria-hidden="true" style="display:none">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button><h4 class="modal-title semi-bold" id="mg_preview_title"><i class="fa fa-play-circle"></i> Video Preview</h4></div>
            <div class="modal-body" style="padding:0">
                <div id="mg_preview_content" style="width:100%;height:500px;background:#000"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

{literal}
<script type="text/javascript">
var mgBaseUrl = '{/literal}{$config['BASE_URL']|escape:'javascript'}{literal}';
var mgCurrentView = '{/literal}{$view}{literal}';
var mgCurrentSourceId = 0;
var mgDiscRunId = 0;
var mgDiscoveredVideos = [];
var mgCurrentTimeframe = '';
var mgCurrentSort = 'newest';

// =========================================================================
// Persistent multi-page selection
// -------------------------------------------------------------------------
// Selection lives in mgSel (id -> entry) and is decoupled from whatever page
// of checkboxes is visible. It survives pagination, status/timeframe/sort
// changes, rescans, and is persisted to localStorage so an accidental refresh
// or tab switch keeps it. The DOM checkboxes only mirror this state.
// =========================================================================
var mgSel = {};                 // id -> {id, source_id, title, duration_formatted, status, ts}
var mgSelStorageKey = 'mg_disc_sel_v1';
var mgSelCap = 2000;            // safety cap for Select All New (protects the server)
var mgSelBusy = false;          // guard against double Select All New
var mgUngrabbable = {'QUEUED':1,'PROCESSING':1,'IMPORTED':1,'SKIPPED':1};

function mgEsc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function mgSelIsGrabbable(st) { return !mgUngrabbable[st]; }
function mgSelEntries() {
    var out = [];
    for (var k in mgSel) { if (mgSel[k] && mgSel[k].source_id === mgCurrentSourceId) out.push(mgSel[k]); }
    out.sort(function(a,b){ return a.ts - b.ts; });
    return out;
}
function mgSelHas(id) { return !!mgSel[id] && mgSel[id].source_id === mgCurrentSourceId; }
function mgSelCount() { return mgSelEntries().length; }

function mgSelSave() { try { localStorage.setItem(mgSelStorageKey, JSON.stringify(mgSel)); } catch(e) {} }
function mgSelLoad() {
    mgSel = {};
    try { var raw = localStorage.getItem(mgSelStorageKey); if (raw) mgSel = JSON.parse(raw) || {}; } catch(e) { mgSel = {}; }
    mgSelSyncUI();
}
function mgSelSyncUI() {
    var entries = mgSelEntries();
    var n = entries.length;
    var cnt = document.getElementById('mg_selected_count');
    if (cnt) cnt.textContent = n;
    var btn = document.getElementById('btn_bulk_grab');
    if (btn) {
        btn.disabled = n === 0;
        btn.innerHTML = n > 0 ? '<i class="fa fa-cloud-download"></i> GRAB SELECTED (' + n + ')' : '<i class="fa fa-cloud-download"></i> GRAB SELECTED';
    }
    var clr = document.getElementById('btn_mg_clear_sel');
    if (clr) clr.style.display = n > 0 ? 'inline-block' : 'none';
    var chips = document.getElementById('mg_selected_chips');
    if (chips) {
        if (n === 0) {
            chips.style.display = 'none';
            chips.innerHTML = '';
        } else {
            var shown = Math.min(entries.length, 50);
            var html = '';
            for (var i = 0; i < shown; i++) {
                var e = entries[i];
                var t = e.title || ('#' + e.id);
                html += '<span class="mg-sel-chip" title="' + mgEsc(t) + '">' + mgEsc(t) +
                    (e.duration_formatted ? '<small>' + mgEsc(e.duration_formatted) + '</small>' : '') +
                    ' <a class="mg-sel-x" href="javascript:void(0)" onclick="mgSelRemove(' + e.id + ')" title="Remove">&times;</a></span>';
            }
            if (entries.length > shown) html += '<span class="text-muted" style="font-size:11px">&hellip; +' + (entries.length - shown) + ' more</span>';
            chips.innerHTML = html;
            chips.style.display = 'block';
        }
    }
    mgSyncPageChecks();
    mgSelSave();
}
function mgSyncPageChecks() {
    var c = document.querySelectorAll('.mg-disc-check');
    for (var i = 0; i < c.length; i++) {
        var id = parseInt(c[i].value);
        var st = c[i].getAttribute('data-status') || 'NEW';
        var disable = !mgSelIsGrabbable(st);
        c[i].disabled = disable;
        c[i].checked = !disable && mgSelHas(id);
    }
}
function mgSelAdd(id, st) {
    if (mgSelHas(id)) return;
    var e = null;
    for (var i = 0; i < mgDiscoveredVideos.length; i++) { if (mgDiscoveredVideos[i].id === id) { e = mgDiscoveredVideos[i]; break; } }
    mgSel[id] = {
        id: id,
        source_id: mgCurrentSourceId,
        title: e && e.title ? e.title : '',
        duration_formatted: e && e.duration_formatted ? e.duration_formatted : '',
        status: st || (e && e.status ? e.status : 'NEW'),
        ts: Date.now()
    };
    mgSelSyncUI();
}
function mgSelRemove(id) { delete mgSel[id]; mgSelSyncUI(); }
function mgSelRemoveBulk(ids) {
    if (!ids || !ids.length) return;
    for (var i = 0; i < ids.length; i++) delete mgSel[ids[i]];
    mgSelSyncUI();
}
function mgSelClearAll() { mgSel = {}; mgSelSyncUI(); }
function mgSelToggleFromRow(id, cb) {
    if (cb && cb.checked) mgSelAdd(id, cb.getAttribute('data-status'));
    else if (cb && !cb.checked) mgSelRemove(id);
}
function mgSelPrunePageRows(rows) {
    var changed = false;
    for (var i = 0; i < rows.length; i++) {
        var v = rows[i];
        var e = mgSel[v.id];
        if (e && e.source_id === mgCurrentSourceId) {
            if (!mgSelIsGrabbable(v.status || '')) { delete mgSel[v.id]; changed = true; }
            else if (v.status && e.status !== v.status) { e.status = v.status; changed = true; }
        }
    }
    return changed;
}
function mgSelSelectPage() {
    var added = 0;
    var c = document.querySelectorAll('.mg-disc-check');
    for (var i = 0; i < c.length; i++) {
        if (!c[i].disabled && !mgSelHas(parseInt(c[i].value))) { mgSelAdd(parseInt(c[i].value), c[i].getAttribute('data-status')); added++; }
    }
    if (added > 0) showToast(added + ' video(s) selected from this page', 'info');
}
function mgSelSelectAllNew() {
    if (!mgCurrentSourceId) return;
    if (mgSelBusy) return;
    var tf = mgCurrentTimeframe ? '&timeframe=' + encodeURIComponent(mgCurrentTimeframe) : '';
    var base = 'videos.php?m=mass_grabber&a=get_discovered&source_id=' + mgCurrentSourceId + '&status=NEW&sort=newest&limit=500&offset=';
    showToast('Reading all new videos for this source...', 'info');
    mgSelBusy = true;
    mgSelCollectNew(base, 0, { added: 0, total: null }, function(acc) {
        mgSelBusy = false;
        if (acc.added > 0) showToast(acc.added + ' new video(s) selected', 'success');
        else showToast('No new videos to select', 'info');
    });
}
function mgSelCollectNew(base, offset, acc, done) {
    mgAjaxGet(base + offset, function(err, data) {
        if (err || !data || !data.status) {
            acc.total = acc.total === null ? 0 : acc.total;
            showToast('Could not read the full list of new videos', 'error');
            done(acc);
            return;
        }
        acc.total = data.total;
        var rows = data.videos || [];
        var capped = false;
        for (var i = 0; i < rows.length; i++) {
            var v = rows[i];
            if (acc.added >= mgSelCap) { capped = true; break; }
            if (!mgSelHas(v.id)) {
                mgSel[v.id] = {
                    id: v.id,
                    source_id: mgCurrentSourceId,
                    title: v.title || '',
                    duration_formatted: v.duration_formatted || '',
                    status: v.status || 'NEW',
                    ts: Date.now()
                };
                acc.added++;
            }
        }
        mgSelSyncUI();
        var next = offset + rows.length;
        if (capped) {
            showToast('Selection capped at ' + mgSelCap + ' videos - grab in smaller batches', 'info');
            done(acc);
        } else if (rows.length > 0 && next < acc.total) {
            mgSelCollectNew(base, next, acc, done);
        } else {
            done(acc);
        }
    });
}

function mgAjax(url, data, callback, timeout) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    if (!(data instanceof FormData)) xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.timeout = timeout || 60000;
    xhr.ontimeout = function() { callback(new Error('Request timed out'), null); };
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) { try { callback(null, JSON.parse(xhr.responseText)); } catch(e) { callback(e, null); } }
            else { callback(new Error('HTTP ' + xhr.status), null); }
        }
    };
    xhr.send(data);
}

function mgAjaxGet(url, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.timeout = 30000;
    xhr.ontimeout = function() { callback(new Error('Request timed out'), null); };
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) { try { callback(null, JSON.parse(xhr.responseText)); } catch(e) { callback(e, null); } }
            else { callback(new Error('HTTP ' + xhr.status), null); }
        }
    };
    xhr.send();
}

// DASHBOARD
function mgRunScheduler() {
    var btn = document.getElementById('btn_run_scheduler');
    var result = document.getElementById('scheduler_result');
    btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Running...'; result.style.display = 'inline';
    var fd = new FormData(); fd.append('a', 'run_scheduler');
    mgAjax('videos.php?m=mass_grabber&a=run_scheduler', fd, function(err, data) {
        btn.disabled = false; btn.innerHTML = '<i class="fa fa-refresh"></i> Run Scheduler Now';
        if (err || !data || !data.status) { result.innerHTML = '<span class="text-danger">Error</span>'; }
        else { var r = data.result; result.innerHTML = '<span class="text-success"><i class="fa fa-check"></i> Scanned ' + r.sources_scanned + '</span>'; }
        setTimeout(function(){ result.style.display = 'none'; }, 5000);
    });
}

if (mgCurrentSourceId === 0) {
    mgAjaxGet('videos.php?m=mass_grabber&a=get_runs&limit=10', function(err, data) {
        var box = document.getElementById('mg_dashboard_runs'); if (!box) return;
        if (err || !data || !data.status || data.runs.length === 0) { box.innerHTML = '<p class="text-muted">No runs yet</p>'; return; }
        var html = '<table class="table mg-table"><thead><tr><th>#</th><th>Source</th><th>Type</th><th>Status</th><th>Found</th><th>New</th><th>Date</th></tr></thead><tbody>';
        for (var i = 0; i < data.runs.length; i++) { var r = data.runs[i]; html += '<tr><td>' + r.id + '</td><td>' + (r.source_name||'-') + '</td><td><span class="label label-default">' + r.run_type + '</span></td><td>' + (r.status==='FINISHED'?'<span class="text-success"><i class="fa fa-check"></i></span>':r.status) + '</td><td>' + r.found_count + '</td><td>' + r.new_count + '</td><td>' + new Date(r.started_at*1000).toLocaleString() + '</td></tr>'; }
        html += '</tbody></table>'; box.innerHTML = html;
    });
}

// SOURCES
function mgShowSourceForm(id) {
    id = id || 0; document.getElementById('mg_src_id').value = id;
    document.getElementById('mg_source_modal_title').textContent = id > 0 ? 'Edit Source' : 'Add Source';
    document.getElementById('mg_source_alert').style.display = 'none'; document.getElementById('mg_source_form').reset();
    if (typeof jQuery !== 'undefined') jQuery('#mg_source_modal').modal('show');
}
function mgEditSource(id) {
    mgAjaxGet('videos.php?m=mass_grabber&a=get_source&id=' + id, function(err, data) {
        if (err || !data || !data.source) return; var s = data.source; mgShowSourceForm(id);
        document.getElementById('mg_src_id').value = s.id; document.getElementById('mg_src_name').value = s.name;
        document.getElementById('mg_src_provider').value = s.provider; document.getElementById('mg_src_url').value = s.discovery_url;
        document.getElementById('mg_src_category').value = s.category_id; document.getElementById('mg_src_quality').value = s.quality;
        document.getElementById('mg_src_auto').value = s.automatic_enabled; document.getElementById('mg_src_schedule_type').value = s.schedule_type;
        document.getElementById('mg_src_schedule_value').value = s.schedule_value; document.getElementById('mg_src_max_per_run').value = s.max_per_run;
        document.getElementById('mg_src_max_pages').value = s.max_pages; document.getElementById('mg_src_delay').value = s.delay_seconds;
        document.getElementById('mg_src_discovery').value = s.discovery_enabled; document.getElementById('mg_src_enabled').value = s.enabled;
    });
}
function mgSaveSource() {
    var alertBox = document.getElementById('mg_source_alert'); var btn = document.getElementById('btn_save_source');
    btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...'; alertBox.style.display = 'none';
    var fd = new FormData();
    fd.append('id', document.getElementById('mg_src_id').value); fd.append('name', document.getElementById('mg_src_name').value);
    fd.append('provider', document.getElementById('mg_src_provider').value); fd.append('discovery_url', document.getElementById('mg_src_url').value);
    fd.append('category_id', document.getElementById('mg_src_category').value); fd.append('quality', document.getElementById('mg_src_quality').value);
    fd.append('automatic_enabled', document.getElementById('mg_src_auto').value); fd.append('schedule_type', document.getElementById('mg_src_schedule_type').value);
    fd.append('schedule_value', document.getElementById('mg_src_schedule_value').value); fd.append('max_per_run', document.getElementById('mg_src_max_per_run').value);
    fd.append('max_pages', document.getElementById('mg_src_max_pages').value); fd.append('delay_seconds', document.getElementById('mg_src_delay').value);
    fd.append('discovery_enabled', document.getElementById('mg_src_discovery').value); fd.append('enabled', document.getElementById('mg_src_enabled').value);
    mgAjax('videos.php?m=mass_grabber&a=save_source', fd, function(err, data) {
        btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Save';
        if (err || !data || !data.status) { var msg = (data&&data.error)?data.error:(err?err.message:'Error'); alertBox.className='alert alert-danger'; alertBox.innerHTML='<i class="fa fa-exclamation-triangle"></i> '+msg; alertBox.style.display='block'; }
        else { if (typeof jQuery!=='undefined') jQuery('#mg_source_modal').modal('hide'); window.location.reload(); }
    });
}
function mgToggleSource(id) { var fd = new FormData(); fd.append('id', id); mgAjax('videos.php?m=mass_grabber&a=toggle_source', fd, function(e,d){ if(!e&&d&&d.status) window.location.reload(); }); }
function mgDeleteSource(id, name) { if (!confirm('Delete source "'+name+'"?')) return; var fd = new FormData(); fd.append('id', id); mgAjax('videos.php?m=mass_grabber&a=delete_source', fd, function(e,d){ if(!e&&d&&d.status) window.location.reload(); }); }
function mgGoToDiscover(sourceId) { window.location.href = 'videos.php?m=mass_grabber&v=discover&source_id=' + sourceId; }

// DISCOVER
var mgCurrentFilter = 'videos';
(function() { var p = new URLSearchParams(window.location.search); var sid = p.get('source_id'); if (sid) { var s = document.getElementById('mg_disc_source'); if (s) { s.value = sid; } mgCurrentSourceId = parseInt(sid); mgSelLoad(); setTimeout(function(){ mgLoadDiscovered(''); }, 300); } })();
if (document.getElementById('mg_disc_source')) {
    document.getElementById('mg_disc_source').addEventListener('change', function() {
        mgCurrentSourceId = parseInt(this.value);
        mgUpdateUrlPreview();
    });
    // Set initial value from dropdown
    var initVal = document.getElementById('mg_disc_source').value;
    if (initVal && parseInt(initVal) > 0) { mgCurrentSourceId = parseInt(initVal); mgSelLoad(); }
}
function mgSetFilter(f) {
    mgCurrentFilter = f;
    var btns = document.querySelectorAll('#mg_disc_filters .btn');
    for (var i=0; i<btns.length; i++) btns[i].className = 'btn btn-sm btn-default';
    event.target.className = 'btn btn-sm btn-default active';
    var q = document.getElementById('mg_disc_query');
    if (f === 'search') { q.placeholder = 'Type search terms...'; q.focus(); }
    else if (f === 'shorts') { q.placeholder = 'Search shorts... (optional)'; }
    else if (f === 'releases') { q.placeholder = 'Search releases... (optional)'; }
    else { q.placeholder = 'Search videos... (leave empty for all)'; }
    mgUpdateUrlPreview();
}
function mgUpdateUrlPreview() {
    var s = document.getElementById('mg_disc_source'); var o = s.options[s.selectedIndex];
    var preview = document.getElementById('mg_disc_url_preview');
    if (!o || !preview) { if(preview) preview.textContent = ''; return; }
    var url = o.getAttribute('data-url') || '';
    var q = document.getElementById('mg_disc_query').value.trim();
    if (q && mgCurrentFilter === 'search') url += '?query=' + encodeURIComponent(q);
    preview.textContent = url;
}
var mgScanTimer = null;
function mgStartScan() {
    var sourceId = document.getElementById('mg_disc_source').value; if (!sourceId || sourceId==0) { showToast('Select a source first.', 'error'); return; }
    var btn = document.getElementById('btn_scan'); var st = document.getElementById('mg_disc_status');
    var list = document.getElementById('mg_disc_video_list');
    btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Starting...'; st.style.display = 'none';
    mgCurrentSourceId = parseInt(sourceId); mgDiscPage = 1;
    document.getElementById('mg_disc_results').style.display = 'block';
    if (list) list.innerHTML = '<div class="text-center" style="padding:30px"><i class="fa fa-spinner fa-spin fa-3x"></i><br><br><strong>Scanning videos...</strong><br><small class="text-muted" id="mg_scan_progress">Preparing scan...</small></div>';
    var fd = new FormData();
    fd.append('source_id', sourceId);
    fd.append('filter', mgCurrentFilter);
    fd.append('query', document.getElementById('mg_disc_query').value.trim());
    fd.append('timeframe', mgCurrentTimeframe);
    fd.append('sort', mgCurrentSort);
    mgAjax('videos.php?m=mass_grabber&a=scan', fd, function(err, data) {
        if (err || !data || !data.status) { btn.disabled=false; btn.innerHTML='<i class="fa fa-search"></i> Scan'; st.className='alert alert-danger'; st.innerHTML='<i class="fa fa-exclamation-triangle"></i> '+(data&&data.error?data.error:'Failed to start scan'); st.style.display='block'; if(list) list.innerHTML=''; return; }
        mgDiscRunId = data.run_id;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Scanning...';
        mgPollScanStatus(data.run_id, btn, st);
    }, 10000);
}
function mgPollScanStatus(runId, btn, st) {
    if (mgScanTimer) clearInterval(mgScanTimer);
    var elapsed = 0;
    mgScanTimer = setInterval(function() {
        elapsed += 2;
        var prog = document.getElementById('mg_scan_progress');
        if (prog) prog.textContent = 'Scanning... (' + elapsed + 's)';
        mgAjaxGet('videos.php?m=mass_grabber&a=scan_status&run_id=' + runId, function(err, data) {
            if (err || !data || !data.status) return;
            if (!data.running) {
                clearInterval(mgScanTimer); mgScanTimer = null;
                btn.disabled = false; btn.innerHTML = '<i class="fa fa-search"></i> Scan';
                if (data.run_status === 'FAILED') {
                    st.className='alert alert-danger';
                    st.innerHTML='<i class="fa fa-exclamation-triangle"></i> Scan failed: '+(data.error_message||'unknown error');
                } else {
                    st.className='alert alert-success';
                    st.innerHTML='<i class="fa fa-check"></i> Found: <strong>'+data.found+'</strong> videos &mdash; New: <strong>'+data.new+'</strong>, Existing: <strong>'+data.existing+'</strong>';
                }
                st.style.display='block';
                mgLoadDiscovered('');
            }
        });
    }, 2000);
}
var mgDiscPage = 1;
var mgDiscPerPage = 10;
var mgDiscTotal = 0;
function mgLoadDiscovered(status, page) {
    page = page || 1; mgDiscPage = page; mgCurrentDiscStatus = status;
    var offset = (page - 1) * mgDiscPerPage;
    var url = 'videos.php?m=mass_grabber&a=get_discovered&source_id=' + mgCurrentSourceId + '&limit=' + mgDiscPerPage + '&offset=' + offset;
    if (status) url += '&status=' + status;
    if (mgCurrentTimeframe) url += '&timeframe=' + mgCurrentTimeframe;
    if (mgCurrentSort) url += '&sort=' + mgCurrentSort;
    var list = document.getElementById('mg_disc_video_list'); list.innerHTML = '<p class="text-muted"><i class="fa fa-spinner fa-spin"></i> Loading...</p>';
    document.getElementById('mg_disc_results').style.display = 'block';
mgAjaxGet(url, function(err, data) {
        if (err || !data || !data.status) { list.innerHTML='<p class="text-muted">Failed to load results</p>'; return; }
        // If the result set shrank (e.g. after a grab) and we are past the last
        // page, fall back to the last existing page instead of an empty list.
        if (data.videos.length === 0 && page > 1 && data.total > 0) {
            var lastPage = Math.ceil(data.total / mgDiscPerPage);
            if (lastPage >= 1 && lastPage !== page) { mgLoadDiscovered(status || '', lastPage); return; }
        }
        mgDiscoveredVideos = data.videos;
        mgDiscTotal = data.total || 0;
        mgSelPrunePageRows(data.videos);
        mgSelSyncUI();
        var showing = offset + data.videos.length;
        document.getElementById('mg_disc_summary').textContent = 'Showing ' + showing + ' of ' + mgDiscTotal;
        document.getElementById('mg_disc_bulk_actions').style.display = data.videos.length > 0 ? 'block' : 'none';
        // Pagination
        var totalPages = Math.ceil(mgDiscTotal / mgDiscPerPage);
        var pagDiv = document.getElementById('mg_disc_pagination');
        if (mgDiscTotal > 0) {
            pagDiv.style.display = 'block';
            var pagerHtml = '';
            pagerHtml += '<li' + ((page <= 1) ? ' class="disabled"' : '') + '><a href="javascript:void(0)" onclick="mgChangePage(-1)"><i class="fa fa-chevron-left"></i></a></li>';
            var startPage = Math.max(1, page - 2);
            var endPage = Math.min(totalPages, page + 2);
            if (startPage > 1) {
                pagerHtml += '<li><a href="javascript:void(0)" onclick="mgGoToPage(1)">1</a></li>';
                if (startPage > 2) pagerHtml += '<li class="disabled"><a>...</a></li>';
            }
            for (var p = startPage; p <= endPage; p++) {
                if (p === page) {
                    pagerHtml += '<li class="active"><a>' + p + '</a></li>';
                } else {
                    pagerHtml += '<li><a href="javascript:void(0)" onclick="mgGoToPage(' + p + ')">' + p + '</a></li>';
                }
            }
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) pagerHtml += '<li class="disabled"><a>...</a></li>';
                pagerHtml += '<li><a href="javascript:void(0)" onclick="mgGoToPage(' + totalPages + ')">' + totalPages + '</a></li>';
            }
            pagerHtml += '<li' + (mgAutoScanning ? ' class="disabled"' : '') + '><a href="javascript:void(0)" onclick="mgChangePage(1)"><i class="fa fa-chevron-right"></i></a></li>';
            document.getElementById('mg_disc_pager').innerHTML = pagerHtml;
        } else { pagDiv.style.display = 'none'; }
        if (data.videos.length === 0) { list.innerHTML='<p class="text-muted">No videos found</p>'; return; }
        var html = '<table class="table mg-table"><thead><tr><th style="width:30px"></th><th style="width:70px"></th><th>Title</th><th>Duration</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        for (var i=0;i<data.videos.length;i++) { var v=data.videos[i];
            var vStatus = v.status || 'NEW';
            var isChecked = mgSelHas(v.id) ? 'checked' : '';
            var cbDisabled = mgSelIsGrabbable(vStatus) ? '' : ' disabled';
            var cbTitle = cbDisabled ? ' title="Already ' + vStatus.toLowerCase() + ' - not grabbable"' : '';
            var thumbHtml = '<div class="mg-mini-player" id="mg_mini_'+v.id+'">';
            if(v.thumbnail_url) thumbHtml+='<img src="'+v.thumbnail_url.replace(/"/g,'"')+'" onerror="this.style.display=\'none\'">';
            thumbHtml+='<div class="mg-mini-overlay" onclick="mgToggleMiniPlayer('+v.id+',event)"><span class="mg-mini-play"><i class="fa fa-play"></i></span></div></div>';
            html+='<tr><td><input type="checkbox" class="mg-disc-check" value="'+v.id+'" data-status="'+vStatus+'" onchange="mgSelToggleFromRow('+v.id+',this)" '+isChecked+cbDisabled+cbTitle+'></td><td>'+thumbHtml+'</td><td><a class="mg-title-link" onclick="mgPreviewVideo('+v.id+',event)"><strong>'+(v.title||'Untitled').substring(0,80)+'</strong></a>';
            if(v.source_url) html+='<br><small class="text-muted">'+v.source_url.substring(0,60)+'</small>'; html+='</td><td>'+(v.duration_formatted||v.duration+'s')+'</td><td><span class="mg-status mg-status-'+v.status.toLowerCase()+'">'+v.status+'</span></td><td>';
            html+='<button class="btn btn-xs btn-success" onclick="return mgGrabSingle('+v.id+',event)"><i class="fa fa-download"></i> Grab</button> ';
            if(v.video_id>0) html+='<a href="videos.php?m=view&VID='+v.video_id+'" class="btn btn-xs btn-default" target="_blank"><i class="fa fa-eye"></i></a>';
            html+='</td></tr>'; } html+='</tbody></table>'; list.innerHTML=html;
    });
}
var mgAutoScanning = false;
function mgChangePage(delta) {
    var newPage = mgDiscPage + delta;
    if (newPage < 1) return;
    var totalPages = Math.ceil(mgDiscTotal / mgDiscPerPage);
    if (newPage > totalPages && !mgAutoScanning) {
        mgAutoDiscover(newPage);
        return;
    }
    mgLoadDiscovered('', newPage);
}
function mgGoToPage(page) {
    var totalPages = Math.ceil(mgDiscTotal / mgDiscPerPage);
    if (page > totalPages && !mgAutoScanning) {
        mgAutoDiscover(page);
        return;
    }
    mgLoadDiscovered('', page);
}
function mgAutoDiscover(nextPage) {
    if (mgAutoScanning) return;
    mgAutoScanning = true;
    var list = document.getElementById('mg_disc_video_list');
    list.innerHTML = '<div class="text-center" style="padding:30px"><i class="fa fa-spinner fa-spin fa-3x"></i><br><br><strong>Discovering more videos...</strong><br><small class="text-muted" id="mg_auto_scan_progress">Fetching next page from source...</small></div>';
    var fd = new FormData();
    fd.append('source_id', mgCurrentSourceId);
    fd.append('filter', mgCurrentFilter);
    fd.append('query', document.getElementById('mg_disc_query').value.trim());
    mgAjax('videos.php?m=mass_grabber&a=scan', fd, function(err, data) {
        if (err || !data || !data.status) {
            mgAutoScanning = false;
            mgLoadDiscovered('', mgDiscPage);
            return;
        }
        mgPollAutoScan(data.run_id, nextPage);
    }, 10000);
}
function mgPollAutoScan(runId, nextPage) {
    var elapsed = 0;
    var prog = document.getElementById('mg_auto_scan_progress');
    var timer = setInterval(function() {
        elapsed += 2;
        if (prog) prog.textContent = 'Scanning... (' + elapsed + 's)';
        mgAjaxGet('videos.php?m=mass_grabber&a=scan_status&run_id=' + runId, function(err, data) {
            if (err || !data || !data.status) return;
            if (!data.running) {
                clearInterval(timer);
                mgAutoScanning = false;
                if (data.run_status === 'FAILED') {
                    showToast('Scan failed: '+(data.error_message||'unknown error'), 'error');
                    mgLoadDiscovered('', mgDiscPage);
                    return;
                }
                var newTotal = (data.counts && data.counts.NEW !== undefined) ? Object.values(data.counts).reduce(function(a,b){return a+b;},0) : mgDiscTotal;
                if (data.found > 0 || data.new > 0) {
                    mgDiscTotal = newTotal;
                }
                mgLoadDiscovered('', nextPage);
            }
        });
    }, 2000);
}
function mgFilterDiscovered(status) {
    mgCurrentDiscStatus = status;
    var b = document.querySelectorAll('#mg_disc_results .btn-group .btn');
    for (var i=0; i<b.length; i++) b[i].className = 'btn btn-default';
    event.target.className = 'btn btn-default active';
    mgLoadDiscovered(status);
}
function mgSetTimeframe(tf) {
    mgCurrentTimeframe = tf;
    var btns = document.querySelectorAll('#mg_disc_timeframe .btn');
    for (var i=0; i<btns.length; i++) btns[i].className = 'btn btn-default';
    event.target.className = 'btn btn-default active';
    mgLoadDiscovered(mgCurrentDiscStatus || '');
}
function mgSetSort(s) {
    mgCurrentSort = s;
    var btns = document.querySelectorAll('#mg_disc_sort .btn');
    for (var i=0; i<btns.length; i++) btns[i].className = 'btn btn-default';
    event.target.className = 'btn btn-default active';
    mgLoadDiscovered(mgCurrentDiscStatus || '');
}
var mgCurrentDiscStatus = '';
// Selection actions now live in the mgSel* module declared above (persistent across pages).
function mgGrabSingle(id, evt) { if(evt) evt.preventDefault(); var fd=new FormData(); fd.append('ids[]',id); fd.append('source_id',mgCurrentSourceId); fd.append('run_id',mgDiscRunId); mgAjax('videos.php?m=mass_grabber&a=bulk_grab',fd,function(e,d){ if(e||!d||!d.status){ showToast('Grab failed: '+(d&&d.error?d.error:'Unknown error'), 'error'); return; } showToast(d.message, 'success'); mgSelRemoveBulk(d.ids_created||[]); mgSelRemoveBulk(d.ids_skipped||[]); mgLoadDiscovered(mgCurrentDiscStatus||'', mgDiscPage); }); return false; }
function mgBulkGrab() {
    var ids = []; var entries = mgSelEntries(); for (var i=0;i<entries.length;i++) ids.push(entries[i].id);
    if (ids.length === 0) return;
    var btn = document.getElementById('btn_bulk_grab');
    var orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Queuing ' + ids.length + '...';
    var fd = new FormData();
    for (var j=0;j<ids.length;j++) fd.append('ids[]', ids[j]);
    fd.append('source_id', mgCurrentSourceId); fd.append('run_id', mgDiscRunId);
    mgAjax('videos.php?m=mass_grabber&a=bulk_grab', fd, function(e, d) {
        btn.disabled = false; btn.innerHTML = orig;
        if (e || !d || !d.status) { showToast('Grab failed: ' + (d && d.error ? d.error : (e ? e.message : 'Unknown error')), 'error'); return; }
        var msg = '<i class="fa fa-check"></i> ' + d.created + ' job(s) queued' + (d.skipped > 0 ? ' &middot; ' + d.skipped + ' skipped (already queued/imported)' : '');
        showToast(msg, 'success');
        mgSelRemoveBulk(d.ids_created || []);
        mgSelRemoveBulk(d.ids_skipped || []);
        mgLoadDiscovered(mgCurrentDiscStatus || '', mgDiscPage);
    });
}

function mgGetEmbedUrl(sourceUrl) {
    if (!sourceUrl) return '';
    var m;
    // YouTube
    m = sourceUrl.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/|youtube\.com\/shorts\/)([a-zA-Z0-9_-]{11})/);
    if (m) return 'https://www.youtube-nocookie.com/embed/' + m[1] + '?autoplay=1&controls=0&rel=0&showinfo=0&modestbranding=1&iv_load_policy=3&mute=1';
    // Vimeo
    m = sourceUrl.match(/vimeo\.com\/(\d+)/);
    if (m) return 'https://player.vimeo.com/video/' + m[1] + '?autoplay=1&controls=0&muted=1&title=0&byline=0&portrait=0';
    // Dailymotion
    m = sourceUrl.match(/dailymotion\.com\/video\/([a-zA-Z0-9]+)/);
    if (m) return 'https://www.dailymotion.com/embed/video/' + m[1] + '?autoplay=1&controls=0&muted=1';
    // XFree
    if (sourceUrl.match(/xfree\.com/i)) {
        m = sourceUrl.match(/[?&]id=(\d+)/);
        if (!m) m = sourceUrl.match(/\/video[\/-](\d+)/);
        if (m) return 'https://www.xfree.com/embed/' + m[1] + '?wmode=opaque';
    }
    return '';
}

var mgActiveMiniPlayer = null;

function mgToggleMiniPlayer(videoId, evt) {
    if (evt) evt.preventDefault();
    var container = document.getElementById('mg_mini_' + videoId);
    if (!container) return;

    // If already playing, stop it
    var existingIframe = container.querySelector('iframe');
    if (existingIframe && existingIframe.src) {
        container.innerHTML = existingIframe.getAttribute('data-thumb-html') || '';
        mgActiveMiniPlayer = null;
        return;
    }

    // Stop any other playing mini player
    if (mgActiveMiniPlayer) {
        var prev = document.getElementById('mg_mini_' + mgActiveMiniPlayer);
        if (prev) {
            var prevIframe = prev.querySelector('iframe');
            if (prevIframe) prev.innerHTML = prevIframe.getAttribute('data-thumb-html') || '';
        }
    }

    // Find video data
    var videoData = null;
    for (var i = 0; i < mgDiscoveredVideos.length; i++) {
        if (mgDiscoveredVideos[i].id == videoId) { videoData = mgDiscoveredVideos[i]; break; }
    }
    if (!videoData) return;

    var embedUrl = mgGetEmbedUrl(videoData.source_url);
    if (!embedUrl) {
        mgPreviewVideo(videoId, evt);
        return;
    }

    // Save current HTML (thumb + overlay)
    var thumbHtml = container.innerHTML;

    // Create iframe with src set immediately
    var iframe = document.createElement('iframe');
    iframe.setAttribute('allow', 'autoplay; encrypted-media; fullscreen');
    iframe.setAttribute('allowfullscreen', '');
    iframe.setAttribute('data-thumb-html', thumbHtml.replace(/"/g, '&quot;'));
    iframe.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;border:0;z-index:3';
    iframe.src = embedUrl;

    // Replace container content
    container.innerHTML = '';
    container.appendChild(iframe);
    mgActiveMiniPlayer = videoId;
}

function mgPreviewVideo(videoId, evt) {
    if (evt) evt.preventDefault();
    var contentDiv = document.getElementById('mg_preview_content');
    var titleEl = document.getElementById('mg_preview_title');
    var modal = jQuery('#mg_preview_modal');

    var videoData = null;
    for (var i = 0; i < mgDiscoveredVideos.length; i++) {
        if (mgDiscoveredVideos[i].id == videoId) { videoData = mgDiscoveredVideos[i]; break; }
    }
    if (!videoData) { showToast('Video not found', 'error'); return; }

    titleEl.innerHTML = '<i class="fa fa-play-circle"></i> ' + (videoData.title || 'Video Preview').substring(0, 80);
    contentDiv.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#fff;background:#000"><i class="fa fa-spinner fa-spin fa-3x"></i></div>';
    modal.modal('show');

    mgAjaxGet('videos.php?m=mass_grabber&a=get_video_preview&video_id=' + videoId, function(err, data) {
        if (err || !data || !data.status) {
            var fallback = videoData.thumbnail_url ? '<img src="' + videoData.thumbnail_url + '" style="max-width:100%;max-height:350px;border-radius:4px">' : '';
            contentDiv.innerHTML = '<div style="padding:20px;text-align:center;color:#fff;background:#111;min-height:100%">' + fallback +
                '<br><a href="' + (videoData.source_url || '#') + '" target="_blank" class="btn btn-primary btn-lg" style="margin-top:15px"><i class="fa fa-external-link"></i> Abrir v\u00eddeo</a></div>';
            return;
        }

        var p = data.preview;

        if (p.stream_url) {
            // HTML5 video player — stream direto resolvido pelo grabber do site
            // (XFree, SonovinhasBR, Pornolandia, etc). Preferido ao iframe por
            // não depender de X-Frame-Options/CORS do provider.
            var isHls = /\.m3u8(\?|#|$)/i.test(p.stream_url);
            var thumbAttr = p.thumbnail ? ' poster="' + p.thumbnail + '"' : '';
            contentDiv.innerHTML = '<video id="mg_preview_player" class="video-js vjs-16-9 vjs-big-play-centered" controls autoplay preload="auto" playsinline webkit-playsinline style="width:100%;height:100%"' + thumbAttr + '>' +
                '<source src="' + p.stream_url + '" type="' + (isHls ? 'application/x-mpegURL' : 'video/mp4') + '">' +
                '<p>Seu navegador n\u00e3o suporta v\u00eddeo HTML5.</p></video>';
            // Init video.js if available
            if (typeof videojs !== 'undefined') {
                videojs('mg_preview_player', {controls: true, autoplay: true, preload: 'auto'});
            }
        } else if (p.embed_url) {
            // iframe player (YouTube, Vimeo, Dailymotion, XFree, etc)
            contentDiv.innerHTML = '<iframe src="' + p.embed_url + '" style="width:100%;height:100%;border:0" allow="autoplay; encrypted-media; fullscreen" allowfullscreen></iframe>';
        } else {
            // No player available - show link (último recurso)
            var thumb = p.thumbnail ? '<img src="' + p.thumbnail + '" style="max-width:100%;max-height:350px;border-radius:4px">' : '';
            contentDiv.innerHTML = '<div style="padding:20px;text-align:center;color:#fff;background:#111;min-height:100%">' + thumb +
                '<br><a href="' + (p.source_url || '#') + '" target="_blank" class="btn btn-primary btn-lg" style="margin-top:15px"><i class="fa fa-external-link"></i> Abrir v\u00eddeo no site</a></div>';
        }
    }, 15000);
}

// QUEUE
function mgLoadJobs(status) {
    var url='videos.php?m=mass_grabber&a=get_jobs'; if(status) url+='?status='+status;
    var list=document.getElementById('mg_queue_list'); if(!list) return; list.innerHTML='<p class="text-muted"><i class="fa fa-spinner fa-spin"></i> Loading...</p>';
    mgAjaxGet(url, function(err,data) {
        if(err||!data||!data.status){list.innerHTML='<p class="text-muted">Failed</p>';return;}
        if(data.jobs.length===0){list.innerHTML='<p class="text-muted">No jobs</p>';return;}
        var html='<table class="table mg-table"><thead><tr><th>ID</th><th>Title</th><th>Source</th><th>Status</th><th>Attempts</th><th>Error</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
        for(var i=0;i<data.jobs.length;i++){var j=data.jobs[i]; html+='<tr><td>'+j.id+'</td><td>'+(j.disc_title||'Unknown').substring(0,60)+'</td><td>'+(j.source_name||'-')+'</td><td><span class="mg-status mg-job-'+j.status.toLowerCase()+'">'+j.status+'</span></td><td>'+j.attempts+'/'+j.max_attempts+'</td><td title="'+(j.error_message||'').replace(/"/g,'&quot;')+'">'+(j.error_code||'-')+'</td><td>'+new Date(j.created_at*1000).toLocaleString()+'</td><td>';
        if(j.status==='PENDING') html+='<button class="btn btn-xs btn-warning" onclick="mgPauseJob('+j.id+')" title="Pause"><i class="fa fa-pause"></i></button> <button class="btn btn-xs btn-danger" onclick="mgCancelJob('+j.id+')" title="Cancel"><i class="fa fa-times"></i></button> ';
        if(j.status==='PROCESSING') html+='<button class="btn btn-xs btn-warning" onclick="mgPauseJob('+j.id+')" title="Pause"><i class="fa fa-pause"></i></button> <button class="btn btn-xs btn-danger" onclick="mgCancelJob('+j.id+')" title="Cancel"><i class="fa fa-times"></i></button> ';
        if(j.status==='PAUSED') html+='<button class="btn btn-xs btn-success" onclick="mgResumeJob('+j.id+')" title="Resume"><i class="fa fa-play"></i></button> ';
        if(j.status==='FAILED') html+='<button class="btn btn-xs btn-info" onclick="mgRetryJob('+j.id+')" title="Retry"><i class="fa fa-refresh"></i></button> ';
        if(j.disc_source_url) html+='<a href="'+j.disc_source_url+'" class="btn btn-xs btn-default" target="_blank" title="Open video"><i class="fa fa-external-link"></i></a>';
        html+='</td></tr>';} html+='</tbody></table>'; list.innerHTML=html;
    });
}
function mgFilterJobs(status) { var b=document.querySelectorAll('#mg-queue .btn-group-sm .btn'); for(var i=0;i<b.length;i++) b[i].className='btn btn-default'; event.target.className='btn btn-default active'; mgLoadJobs(status); }
function mgCancelJob(id) { if(!confirm('Cancel this job?')) return; var fd=new FormData(); fd.append('id',id); mgAjax('videos.php?m=mass_grabber&a=cancel_job',fd,function(e,d){ if(d&&d.message) showToast(d.message, 'info'); mgLoadJobs(''); }); }
function mgPauseJob(id) { var fd=new FormData(); fd.append('id',id); mgAjax('videos.php?m=mass_grabber&a=pause_job',fd,function(e,d){ if(d&&d.message) showToast(d.message, 'info'); mgLoadJobs(''); }); }
function mgResumeJob(id) { var fd=new FormData(); fd.append('id',id); mgAjax('videos.php?m=mass_grabber&a=resume_job',fd,function(e,d){ if(d&&d.message) showToast(d.message, 'info'); mgLoadJobs(''); }); }
function mgRetryJob(id) { var fd=new FormData(); fd.append('id',id); mgAjax('videos.php?m=mass_grabber&a=retry_job',fd,function(e,d){ if(d&&d.message) showToast(d.message, 'info'); mgLoadJobs(''); }); }
function mgProcessNow() { var btn=document.getElementById('btn_process_now'); btn.disabled=true; btn.innerHTML='<i class="fa fa-spinner fa-spin"></i> Starting...'; mgAjax('videos.php?m=mass_grabber&a=process_now',new FormData(),function(e,d){ btn.disabled=false; btn.innerHTML='<i class="fa fa-play"></i> Process Now'; if(d&&d.message) showToast(d.message, 'info'); setTimeout(function(){ mgLoadJobs(''); }, 2000); }); }
function mgPauseAll() { if(!confirm('Pause ALL pending jobs?')) return; mgAjax('videos.php?m=mass_grabber&a=pause_all',new FormData(),function(e,d){ if(d&&d.message) showToast(d.message, 'info'); mgLoadJobs(''); }); }
function mgResumeAll() { mgAjax('videos.php?m=mass_grabber&a=resume_all',new FormData(),function(e,d){ if(d&&d.message) showToast(d.message, 'info'); mgLoadJobs(''); }); }

// REAL-TIME TOGGLE
function mgGetRealtimeStatus() {
    mgAjaxGet('videos.php?m=mass_grabber&a=get_realtime_status', function(err, data) {
        if (err || !data || !data.status) return;
        var btn = document.getElementById('btn_realtime');
        if (!btn) return;
        if (data.enabled) {
            btn.className = 'btn btn-sm btn-danger';
            btn.innerHTML = '<i class="fa fa-bolt"></i> Real-time: ON';
        } else {
            btn.className = 'btn btn-sm btn-default';
            btn.innerHTML = '<i class="fa fa-bolt"></i> Real-time: OFF';
        }
    });
}
function mgToggleRealtime() {
    var btn = document.getElementById('btn_realtime');
    btn.disabled = true;
    var origHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> ...';
    var fd = new FormData();
    fd.append('a', 'toggle_realtime');
    mgAjax('videos.php?m=mass_grabber&a=toggle_realtime', fd, function(err, data) {
        btn.disabled = false;
        if (err || !data || !data.status) {
            btn.innerHTML = origHTML;
            var msg = (data && data.error) ? data.error : (err ? err.message : 'Unknown error');
            showToast('Failed: ' + msg, 'error');
            return;
        }
        if (data.enabled) {
            btn.className = 'btn btn-sm btn-danger';
            btn.innerHTML = '<i class="fa fa-bolt"></i> Real-time: ON';
        } else {
            btn.className = 'btn btn-sm btn-default';
            btn.innerHTML = '<i class="fa fa-bolt"></i> Real-time: OFF';
        }
    });
}

// HISTORY
function mgViewRunLogs(runId) { var c=document.getElementById('mg_log_content'); c.innerHTML='Loading logs...'; if(typeof jQuery!=='undefined') jQuery('#mg_log_modal').modal('show'); mgAjaxGet('videos.php?m=mass_grabber&a=get_logs&run_id='+runId,function(err,data){ if(err||!data||!data.status){c.innerHTML='Failed.';return;} if(data.logs.length===0){c.innerHTML='No logs.';return;} var t=''; for(var i=0;i<data.logs.length;i++){var l=data.logs[i]; var ts=new Date(l.created_at*1000).toLocaleTimeString(); var col=l.level==='ERROR'?'#ff6b6b':l.level==='WARNING'?'#ffd93d':l.level==='DEBUG'?'#888':'#e0e0e0'; t+='<span style="color:#888">['+ts+']</span> <span style="color:'+col+'">['+l.level+']</span> <span style="color:#6bc5ff">'+l.event+'</span> '+l.message+'\n';} c.innerHTML=t;}); }

// INIT
document.addEventListener('DOMContentLoaded', function() {
    var st = document.getElementById('mg_src_schedule_type');
    if(st) st.addEventListener('change', function(){ var h=document.getElementById('mg_schedule_help'); if(!h)return; switch(this.value){case'hourly':h.textContent='Minutes between runs';break;case'daily':h.textContent='HH:MM for daily';break;case'weekly':h.textContent='Day name (e.g. monday)';break;case'interval':h.textContent='Seconds between runs (min 300)';break;} });
    if(mgCurrentView==='queue') mgLoadJobs('');
    if(mgCurrentView==='queue') mgGetRealtimeStatus();

    // Stop video when preview modal closes
    if (typeof jQuery !== 'undefined') {
        jQuery('#mg_preview_modal').on('hidden.bs.modal', function() {
            var contentDiv = document.getElementById('mg_preview_content');
            if (!contentDiv) return;
            // Pause video.js player
            var vid = document.getElementById('mg_preview_player');
            if (vid && typeof videojs !== 'undefined') {
                try { videojs(vid).pause(); } catch(e) {}
            }
            // Remove iframe to stop YouTube/XFree/etc
            var iframe = contentDiv.querySelector('iframe');
            if (iframe) iframe.src = '';
            // Clear content
            contentDiv.innerHTML = '';
        });
    }
});
</script>
{/literal}
