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

        document.addEventListener("click", function (e) {
            const wrapper = document.querySelector('.admin-user-dropdown');
            if (wrapper && wrapper.contains(e.target)) {
                return;
            }
            dropdownMenu.style.display = "none";
        });

        dropdownMenu.addEventListener("click", function(e) {
            e.stopPropagation();
        });
    }
});
