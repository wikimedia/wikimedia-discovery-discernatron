(function ($) {
    var valueToClass = ['irrelevant', 'maybe', 'probably', 'relevant'],
        onChange = function () {
            var $this = $(this),
                value = parseInt($this.val()),
                $row = $this.closest('.row');

            $row.removeClass(valueToClass.join(' '));
            if (!isNaN(value)) {
                $row.addClass(valueToClass[value]);
                $row.find('.rating').text($this.siblings('label').text());
            } else {
                $row.find('.rating').text('');
            }
        },
        onClickForNext = function (evt) {
            // Find the currently selected choice
            var next,
                row = $(this).closest('.row'),
                selected = parseInt(row.find('input:checked').val()),
                skip = 'label, input, a, .glyphicon',
                // is there a better way? evt.toElement was missing in some FF
                // versions. this is the element the event was bound to, not the
                // one that was clicked.
                $target = $(evt.toElement ||
                            (evt.originalEvent && evt.originalEvent.target) ||
                            this);

            // Don't override real clicks. We have to check originalEvent because toElement
            // isn't populated in firefox 46
            if ($target.is(skip)) {
                return;
            }

            // if nothing selected default to last so we wrap to beginning
            if (isNaN(selected)) {
                selected = 4;
            }
            next = (selected + 1) % 5;
            if (next == 4) {
                // non existent value means unselect
                row.find('input:checked').prop('checked', false);
                onChange.apply(row);
            } else {
                onChange.apply(row.find('input[value=' + (next) + ']').prop('checked', true));
            }
        },
        showSnippet = function ($target) {
            $target.toggle(false);
            $target.siblings('.hide-snippet').toggle(true);
            $target.closest('.result').find('.snippet').toggle(true);
        },
        hideSnippet = function ($target) {
            $target.toggle(false);
            $target.siblings('.show-snippet').removeClass('hidden').toggle(true);
            $target.closest('.result').find('.snippet').toggle(false);
        };

    $(document).ready(function () {
        $('input:checked').each(onChange);
        $('input[type=radio]').change(onChange);
        $('.click-for-next').click(onClickForNext);
        $('.show-snippet').click(function () {
            showSnippet($(this));
        });
        $('.show-all-snippets').click(function () {
            showSnippet($('.show-snippet'));
            $(this).toggle(false);
            $('.hide-all-snippets').toggle(true);
        });
        $('.hide-snippet').removeClass('hidden').click(function () {
            hideSnippet($(this));
        });
        $('.hide-all-snippets').removeClass('hidden').click(function () {
            hideSnippet($('.hide-snippet'));
            $(this).toggle(false);
            $('.show-all-snippets').removeClass('hidden').toggle(true);
        });
    });
}(jQuery));
