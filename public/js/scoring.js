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
                skip = 'label, input, a, .glyphicon';
    
            // Don't override real clicks. We have to check originalEvent because toElement
            // isn't populated in firefox 46
            if ($(evt.toElement).is(skip) || $(evt.originalEvent.target).is(skip)) {
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
        onShowSnippet = function (evt) {
            $(this).toggle(false);
            $(this).siblings('.hide-snippet').removeClass('hidden').toggle(true);
            $(this).closest('.result').find('.snippet').removeClass('hidden').toggle(true);
        },
        onHideSnippet = function (evt) {
            $(this).toggle(false);
            $(this).siblings('.show-snippet').toggle(true);
            $(this).closest('.result').find('.snippet').toggle(false);
        };
    
    $(document).ready(function () {
        $('input:checked').each(onChange);
        $('input[type=radio]').change(onChange);
        $('.click-for-next').click(onClickForNext);
        $('.show-snippet').removeClass('hidden').click(onShowSnippet);
        $('.hide-snippet').click(onHideSnippet);
    });
}(jQuery));
