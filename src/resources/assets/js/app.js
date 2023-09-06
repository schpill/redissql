$(document).ready(function() {
    $('[rel=tooltip]').tooltip({
        placement: 'bottom'
    });

    $('[rel=tooltip-b]').tooltip({
        placement: 'bottom'
    });

    $('[rel=tooltip-t]').tooltip({
        placement: 'top'
    });

    $('[rel=tooltip-l]').tooltip({
        placement: 'left'
    });

    $('[rel=tooltip-r]').tooltip({
        placement: 'right'
    });

    $('.hasmany').click(function() {
        const $action = $(this).data('action');

        if ($action.match('add_')) {
            $(this).data('action', $action.replace('add_', 'remove_'));
        } else {
            $(this).data('action', $action.replace('remove_', 'add_'));
        }

        $.post($action, {}, function(data) {});
    });

    $('.iswysiwyg').wysihtml5();
});


function paginationGoPage(page)
{
    $('#export').val(0);
    $('#page').val(page);
    $('#listForm').submit();
}

function paginationOrder(order)
{
    const $order = $('#order');
    const $direction = $('#direction');

    var oldOrder = $order.val();
    var oldDirection = $direction.val();

    let direction = 'ASC';

    if (oldOrder !== order) {
        direction = 'ASC';
    } else {
        direction = oldDirection === 'ASC' ? 'DESC' : 'ASC';
    }

    $order.val(order);
    $direction.val(direction);

    $('#export').val(0);
    $('#page').val(1);
    $('#listForm').submit();
}

function showHide(id)
{
    const $q = $('#' + id);

    if(!$q.is(':visible')) {
        $q.slideDown();
    } else {
        $q.slideUp();
    }
}

function copyRow()
{
    const $q = $('#search');

    const divs = $q.find('div');
    const divRef = divs[divs.length - 1];
    const newDiv = document.createElement('div');

    $(newDiv).html($(divRef).html());
    $q.append(newDiv);

    const i = $(divRef).find('i');

    i.remove();

    const newI = document.createElement('i');

    $(newI).attr('rel', 'tooltip');
    $(newI).attr('title', 'Delete this criteria');
    $(newI).addClass('link');
    $(newI).addClass('fa');
    $(newI).addClass('fa-trash-o');

    $(newI).click(function () {
        divRef.remove();
    });

    $(divRef).append(newI);
}

function search()
{
    let fields      = '';
    let operators   = '';
    let values      = '';

    $('.fields').each(function() {
        if (0 < fields.length) {
            fields += '##';
        }

        fields += $(this).val();
    });

    $('.operators').each(function() {
        if (0 < operators.length) {
            operators += '##';
        }

        operators += $(this).val();
    });

    $('.values').each(function() {
        if (0 < values.length) {
            values += '##';
        }

        values += $(this).val();
    });

    var query = fields + '%%' + operators + '%%' + values;

    $('#order').val('id');
    $('#direction').val('ASC');
    $('#page').val(1);
    $('#export').val(0);
    $('#query').val(query);
    $('#listForm').submit();
}

function makeExport()
{
    $('#export').val(1);
    $('#listForm').submit();
}

function editRow(id)
{
    document.location.href = urlEdit.split('id=id').join('id=' + id);
}

function makeExportPdf()
{
    $('#export').val('pdf');
    $('#listForm').submit();
}

function selfPage()
{
    document.location.href = document.URL;
}
