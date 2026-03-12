document.addEventListener('DOMContentLoaded', function() {
    const userBtn = document.querySelector(".admin-user-pill");
    const dropdownMenu = document.querySelector(".admin-dropdown-menu");

    if (userBtn && dropdownMenu) {
        userBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            const notifDropdown = document.getElementById('notifDropdown');
            if (notifDropdown) notifDropdown.classList.remove('show');
            if (dropdownMenu.style.display === "flex") {
                dropdownMenu.style.display = "none";
            } else {
                dropdownMenu.style.display = "flex";
            }
        });

        document.addEventListener("click", function () {
            dropdownMenu.style.display = "none";
            const notifDropdown = document.getElementById('notifDropdown');
            if (notifDropdown) notifDropdown.classList.remove('show');
        });

        dropdownMenu.addEventListener("click", function(e) {
            e.stopPropagation();
        });
    }
});
