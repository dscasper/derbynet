<?php @session_start();
require_once('inc/banner.inc');
require_once('inc/data.inc');
?><!DOCTYPE html>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
<title>Replay Recording</title>
<?php
require('inc/stylesheet.inc');
require('inc/servername.inc');

$server = server_name();
$path = request_path();

$url = $server . $path;

$last = strrpos($url, '/');
if ($last === false) {
  $last = -1;
}

// TODO Layout of camera page:
//    Allow turning off camera preview
//    https link
//
// TODO Camera selection for the camera page doesn't work for iphone, at least, or Safari Mac
//
// TODO What resolution is transmitted if camera doesn't ask for its window
// size?  Should viewer send its ideal size to remote camera?
//

// TODO: See https://www.w3.org/TR/image-capture/#example4 for handling manual
// focus for cameras that support it.

// Don't force http !
$kiosk_url = '//'.substr($url, 0, $last + 1).'kiosk.php';
if (isset($_REQUEST['address'])) {
  $kiosk_url .= "?address=".$_REQUEST['address'];
}
?>
<link rel="stylesheet" type="text/css" href="css/jquery-ui.min.css"/>
<link rel="stylesheet" type="text/css" href="css/replay.css"/>
<script type="text/javascript" src="js/jquery.js"></script>
<script type="text/javascript" src="js/ajax-setup.js"></script>
<script type="text/javascript" src="js/jquery-ui.min.js"></script>
<script type="text/javascript" src="js/screenfull.min.js"></script>
<script type="text/javascript" src="js/adapter.js"></script>
<script type="text/javascript" src="js/message-poller.js"></script>
<script type="text/javascript" src="js/viewer-signaling.js"></script>
<script type="text/javascript" src="js/video-capture.js"></script>
<script type="text/javascript" src="js/circular-frame-buffer.js"></script>
<script type="text/javascript" src="js/video-device-picker.js"></script>
<script type="text/javascript">

function logmessage(txt) {
  $("<p></p>").text(txt).appendTo($("#log"));
  let msgs = $("#log p");
  if (msgs.length > 20) {
    msgs.slice(0, -20).remove();
  }
}

// TODO Poll for messages.
// In the meantime, use the existing replay protocol
function poll_as_replay() {
  $.ajax("action.php",
  {type: 'POST',
    data: {action: 'json.replay.message',
           status: 0,
           'finished-replay': 0},
    success: function(data) {
      for (let i = 0; i < data.replay.length; ++i) {
        handle_replay_message(data.replay[i]);
      }
    }
  });
}

g_upload_videos = <?php echo read_raceinfo_boolean('upload-videos') ? "true" : "false"; ?>;
g_video_name_root = "";
// If a replay is triggered by timing out after a RACE_STARTS, then ignore any
// subsequent REPLAY messages until the next START.
g_preempted = false;


var g_remote_poller;
var g_recorder;

var g_replay_options = {
  count: 2,
  rate: 0.5,
  length: 4
};

function parse_replay_options(cmdline) {
  g_replay_options.count = parseInt(cmdline.split(" ")[2]);
  g_replay_options.rate = parseFloat(cmdline.split(" ")[3]);
}

// If non-zero, holds the timeout ID of a pending timeout that will trigger a
// replay based on the start of a heat.
var g_replay_timeout = 0;
// Milliseconds short of g_replay_length to start a replay after a race start.
var g_replay_timeout_epsilon = 0;

function handle_replay_message(cmdline) {
  if (cmdline.startsWith("HELLO")) {
  } else if (cmdline.startsWith("TEST")) {
    parse_replay_options(cmdline);
    on_replay();
  } else if (cmdline.startsWith("START")) {  // Setting up for a new heat
    g_video_name_root = cmdline.substr(6).trim();
    g_preempted = false;
  } else if (cmdline.startsWith("REPLAY")) {
    // REPLAY skipback showings rate
    //  skipback and rate are ignored, but showings we can honor
    // (Must be exactly one space between fields:)
    parse_replay_options(cmdline);
    if (!g_preempted) {
      on_replay();
    }
  } else if (cmdline.startsWith("CANCEL")) {
  } else if (cmdline.startsWith("RACE_STARTS")) {
    parse_replay_options(cmdline);
    g_replay_timeout = setTimeout(
      function() {
        g_preempted = true;
        on_replay();
      },
      g_replay_options.length * 1000 - g_replay_timeout_epsilon);
  } else {
    console.log("Unrecognized replay message: " + cmdline);
  }
}

$(function() { setInterval(poll_as_replay, 250); });

function on_stream_ready(stream) {
  $("#waiting-for-remote").addClass('hidden');
  g_recorder = new CircularFrameBuffer(stream, g_replay_options.length);
  g_recorder.start();
  document.getElementById("preview").srcObject = stream;
}

function on_remote_stream_ready(stream) {
  let resize = function(w, h) {
    $("#recording-stream-info").removeClass('hidden');
    $("#recording-stream-size").text(w + 'x' + h);
  };
  resize(-1, -1);  // Says we've got a stream, even if no frames yet.
  on_stream_ready(stream);
  g_recorder.on_resize(resize);
}

function on_device_selection(selectq) {
  // If a stream is already open, stop it.
  stream = document.getElementById("preview").srcObject;
  if (stream != null) {
    stream.getTracks().forEach(function(track) {
      track.stop();
    });
  }	

  let device_id = selectq.find(':selected').val();

  if (typeof(navigator.mediaDevices) == 'undefined') {
    $("no-camera-warning").toggleClass('hidden', device_id == 'remote');
  }

  if (device_id == 'remote') {
    $("#waiting-for-remote").removeClass('hidden');
    let id = make_viewer_id();
    console.log("Viewer id is " + id);
    g_remote_poller = new RemoteCamera(
      id,
      {width: $(window).width(),
       height: $(window).height()},
      on_remote_stream_ready);
  } else {
    $("#recording-stream-info").addClass('hidden');
    navigator.mediaDevices.getUserMedia(
      { video: {
          deviceId: device_id,
          width: { ideal: $(window).width() },
          height: { ideal: $(window).height() }
        }
      })
    .then(on_stream_ready);
  }
}

$(window).on('resize', function(event) { on_setup(); });

$(function() {
    if (typeof(window.MediaRecorder) == 'undefined') {
      g_upload_videos = false;
      $("#user_agent").text(navigator.userAgent);
      $("#recorder-warning").removeClass('hidden');
    }
});

function build_device_picker() {
  let selected = $("#device-picker :selected").prop('value');
  video_devices(
    selected,
    (found, options) => {
      options.push($("<option value='remote'>Remote Camera</option>"));
      let picker = $("#device-picker");
      picker.empty()
            .append(options)
            .on('input', event => { on_device_selection(picker); });
      if (!found) {
        on_setup();
      }
      picker.trigger("create");
      on_device_selection(picker);
    });
}

$(function() {
    if (typeof(navigator.mediaDevices) == 'undefined') {
      $("#no-camera-warning").removeClass('hidden');
      if (window.location.protocol == 'http:') {
        var https_url = "https://" + window.location.hostname + window.location.pathname;
        $("#no-camera-warning").append("<p>You may need to switch to <a href='" +  https_url + "'>" + https_url + "</a></p>");
      }
    } else {
      navigator.mediaDevices.ondevicechange = function(event) { build_device_picker(); };
    }

    build_device_picker();
});

// Posts a message to the page running in the interior iframe.
function announce_to_interior(msg) {
  document.querySelector("#interior").contentWindow.postMessage(msg, '*');
}

function upload_video(root, blob) {
  if (blob) {
    console.log("Uploading video");
    let form_data = new FormData();
    form_data.append('video', blob, root + ".mkv");
    form_data.append('action', 'json.video.upload');
    $.ajax("action.php",
           {type: 'POST',
             data: form_data,
             processData: false,
             contentType: false,
             success: function(data) {
               console.log('video upload success:');
               console.log(data);
             }
           });
  }
}

// TODO My working theory is that the captureStream() from an offscreen canvas
// doesn't produce any frames.

function on_replay() {
  // Capture these global variables before starting the asynchronous operation,
  // because they're reasonably likely to be clobbered by another queued message
  // from the server.
  var upload = g_upload_videos;
  var root = g_video_name_root;
  // If this is a replay triggered after RACE_START, make sure we don't start
  // another replay for the same heat.

  if (g_replay_timeout > 0) {
    clearTimeout(g_replay_timeout);
  }
  g_replay_timeout = 0;

  announce_to_interior('replay-started');
  g_recorder.stop();

  let playback = document.querySelector("#playback");
  playback.width = $(window).width();
  playback.height = $(window).height();
  $("#playback-background").show('slide', function() {
      let playback_start_ms = Date.now();
      let vc;
      g_recorder.playback(playback,
                          g_replay_options.count,
                          g_replay_options.rate,
                          function(pre_canvas) {
                            if (upload && root != "") {
                              vc = new VideoCapture(pre_canvas.captureStream());
                            } else if (!upload) {
                              console.log("No video upload planned.");
                            } else {
                              console.log("No video root name specified; will not upload.");
                            }
                          },
                          function() {
                            if (vc) {
                              vc.stop(function(blob) { upload_video(root, blob); });
                              vc = null;
                            }
                          },
                          function() {
                            $("#playback-background").hide('slide');
                            announce_to_interior('replay-ended');
                            g_recorder.start();
                          });
    });
}

function on_proceed() {
  $("#interior").attr('src', $("#inner_url").val());

  if (document.getElementById('go-fullscreen').checked) {
    if (screenfull.enabled) {
      screenfull.request();
    }
  }

  $("#replay-setup").hide('slide', {direction: 'down'});
  $("#playback-background").hide('slide').removeClass('hidden');
  $("#interior").removeClass('hidden');
  $("#click-shield").removeClass('hidden');
}

function on_setup() {
  $("#click-shield").addClass('hidden');
  $("#replay-setup").show('slide', {direction: 'down'});
}

</script>
</head>
<body>
  <div id="fps"
    style="position: fixed; bottom: 0; right: 0; width: 80px; height: 20px; z-index: 1000; text-align: right;"></div>

<iframe id="interior" class="hidden full-window">
</iframe>

<div id="click-shield" class="hidden full-window"
     onclick="on_setup(); return false;"
     oncontextmenu="on_replay(); return false;">
</div>

<div id="playback-background" class="hidden full-window">
  <canvas id="playback" class="full-window">
  </canvas>
</div>

<div id="replay-setup" class="full-window block_buttons">
  <?php make_banner('Replay'); ?>
<!-- Uncomment for debugging 
  <div id="log"></div>
-->
  <div id="recorder-warning" class="hidden">
    <h2>This browser does not support MediaRecorder.</h2>
    <p>Replay is still possible, but you can't upload videos.</p>
    <p>Your browser's User Agent string is:<br/><span id="user_agent"></span></p>
  </div>

  <div id="no-camera-warning" class="hidden">
     <h2 id='reject'>Access to cameras is blocked.</h2>
  </div>

  <div id="preview-container">
    <video id="preview" autoplay muted playsinline>
    </video>
    <div id="waiting-for-remote" class="hidden">
      <p>Waiting for remote camera to connect...</p>
    </div>
  </div>

  <div id="device-picker-div">
    <select id="device-picker"><option>Please wait</option></select>
  </div>
  <p id="recording-stream-info" class="hidden">
    Recording at <span id="recording-stream-size"></span>
  </p>

  <input type="checkbox" id="go-fullscreen" checked="checked"/>
  <label for="go-fullscreen">Change to fullscreen?</label>
  <br/>

  <label for="inner_url">URL:</label>
  <input type="text" name="inner_url" id="inner_url" size="100"
         value="<?php echo htmlspecialchars($kiosk_url, ENT_QUOTES, 'UTF-8'); ?>"/>
  <input type="button" value="Proceed" onclick="on_proceed();"/>
</div>

</body>
</html>
