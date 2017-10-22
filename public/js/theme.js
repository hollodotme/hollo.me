(function () {
    var sidebar = document.getElementById('sidebar');
    var sidebarToggle = document.getElementById('sidebar-toggle');
    sidebarToggle.addEventListener('click', function (e) {
        if (sidebarToggle.getAttribute('class') === 'open') {
            sidebar.removeAttribute('class');
            sidebarToggle.removeAttribute('class');
        }
        else {
            sidebar.setAttribute('class', 'open');
            sidebarToggle.setAttribute('class', 'open');
        }
    });
    $('a[data-toggle]').click(function (e) {
        e.preventDefault();
        var target = $($(this).data('toggle'));
        target.toggle();
    });
})();
