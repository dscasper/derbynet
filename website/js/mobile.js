'use strict';

// jquery-mobile provides some awesome-looking UI elements, but they come with
// some invasive javascript that wants to take over the whole page.  Here are
// some "pseudo-mobile" UI elements with much more modest ambitions.

function mobile_radio(radios) {
  $(radios).each(function(i, radio) {
    radio = $(radio);
    radio
      .wrap("<div class='mradio'/>")
      .before(function() {
        var label = $("label[for='" + $(this).attr('id') + "']");
        if ($(this).is(':checked')) {
          label.addClass('on');
        } else {
          label.addClass('off');
        }
        return label;
      })
      .on('change', function() {
        // Mark all the labels for the same name to be 'off'
        $("input:radio[name='" + $(this).attr('name') + "']").each(function() {
          $("label[for='" + $(this).attr('id') + "']")
            .removeClass('on').addClass('off');
        });
        // ... and then mark just this one radio button 'on'
        $("label[for='" + $(this).attr('id') + "']")
          .addClass('on').removeClass('off');
      });
  });
}

function mobile_select(selects) {
  $(selects).each(function(i, select) {
    select = $(select);
    select.wrap($("<div class='mselect'>" +
                  "<div class='mselect-inner'></div>" +
                  "</div>")
                .addClass(select.attr('data-wrapper-class')))
      .before(function() {
        return $("<span></span>").text($(this).find("option:selected").text());
      })
      .on("change", function() {
        $(this).prev("span").text($(this).find("option:selected").text());
      });
  });
}

// Update appearance to match the current selection
function mobile_select_refresh(select) {
  select = $(select);
  select.prev("span").text(select.find("option:selected").text());
}

function mobile_text(texts) {
  $(texts).each(function(i, text) {
    text = $(text);
    text.wrap("<div class='mtext'/>");
  });
}


// checkbox is an element to wrap.
function flipswitch(checkboxes) {
  // Consider each checkbox individually, for classes and checked state
  $(checkboxes).each(function (i, checkbox) {
    checkbox = $(checkbox);
    // Since the checkbox per se won't be visible, any classes assigned to the
    // checkbox are assumed to be meant for the enclosing div.flipswitch,
    // including the flipswitch class that triggered the conversion.  Move the
    // class string accordingly.
    var classes = checkbox.attr('class');

    var id = checkbox.attr('id');
    var offtext = checkbox.attr('data-off-text') || 'Off';
    var ontext = checkbox.attr('data-on-text') || 'On';

    checkbox
      .removeClass(classes)
      .wrap('<div>')
      .before($('<span class="on"></span>').text(ontext))
      .before($('<span class="off"></span>').text(offtext))
      .on('change', function(event) { checkbox.parent().toggleClass('checked', checkbox.is(':checked')); })
      .parent()
      .addClass(classes)
      .toggleClass('checked', checkbox.is(':checked'))
      .on('click', function(event) {
        checkbox.trigger(event);
      });

    var em = parseInt(checkbox.parent().css('font-size'));

    var offtext_size = measure_text(offtext, em);
    var ontext_size = measure_text(ontext, em);
    var off_shim = 0;
    var on_shim = 0;
    var text_size = offtext_size;
    if (offtext_size > ontext_size) {
      on_shim = (offtext_size - ontext_size) / 2;
    } else {
      off_shim = (ontext_size - offtext_size) / 2;
      text_size = ontext_size;
    }

    var flip_width = (5.875 - 1.3125) * em + text_size;
    var checked_flip_padleft = (4.333 - 1.3125) * em + text_size;
    var checked_text_indent = -((2.6 - 1.3125) * em + text_size - on_shim);

    if (checkbox.is(':checked')) {
      checkbox.parent()
        .css('width', '1.8125em')
        .css('padding-left', checked_flip_padleft);
    } else {
      checkbox.parent()
        .css('width', flip_width + 'px')
        .css('padding-left', '.2708em');
    }
    checkbox.parent().find('.on').css('text-indent', checked_text_indent + 'px');
    checkbox.parent().find('.off').css('text-indent', (em + off_shim) + 'px');
    // Counting on having this execute AFTER the checkbox has been checked
    checkbox.parent().on('change', function() {
      if (checkbox.is(':checked')) {
        checkbox.parent()
          .css('width', '1.8125em')
          .css('padding-left', checked_flip_padleft);
      } else {
        checkbox.parent()
          .css('width', flip_width + 'px')
          .css('padding-left', '.2708em');
      }
    });
  });
}

function measure_text(text, font_size) {
  var span = $("<span/>").text(text)
      .css('font-size', font_size + 'px')
      .css('font-family', 'sans-serif')
      .css('position', 'absolute')
      .css('display', 'hidden')
      .appendTo($("body"));
  var w = span[0].clientWidth;
  span.remove();
  return w;
}

$(function() {
  flipswitch($("input.flipswitch[type=checkbox]"));
  // While checkboxes need class='flipswitch' for conversion, <select> and
  // <input type=radio> and <input type=text> elements get converted unless
  // they're marked with a not-mobile class.
  mobile_select($("select").not("not-mobile"));
  mobile_radio($("input:radio").not("not-mobile"));
  mobile_text($("input[type='text']").not("not-mobile"));
});
