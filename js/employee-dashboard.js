document.addEventListener('DOMContentLoaded', function() {
    // User dropdown toggle
    const userBtn = document.querySelector('.user-btn');
    const userDropdown = document.querySelector('.user-dropdown');
    
    if(userBtn && userDropdown) {
        userBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const notifDropdown = document.getElementById('notifDropdown');
            if (notifDropdown) notifDropdown.classList.remove('show');
            userDropdown.classList.toggle('show');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!userBtn.contains(e.target) && !userDropdown.contains(e.target)) {
                userDropdown.classList.remove('show');
            }
        });
    }

    // Mobile Navbar Toggle
    const navbarToggler = document.getElementById('navbarToggler');
    const navbarCollapse = document.getElementById('navbarCollapse');

    if (navbarToggler && navbarCollapse) {
        navbarToggler.addEventListener('click', function() {
            navbarCollapse.classList.toggle('show');
        });
    }

    // Optional: Add simple animation to stats numbers if needed
    // const statValues = document.querySelectorAll('.stat-value');
    // statValues.forEach(el => {
    //     // Animation logic can go here
    // });

    // File Upload Display
    const fileInput = document.getElementById('attachment');
    const fileNameSpan = document.querySelector('.file-name');
    const previewContainer = document.getElementById('attachment-preview');

    if (fileInput && fileNameSpan) {
        fileInput.addEventListener('change', function(e) {
            if (this.files && this.files.length > 0) {
                const file = this.files[0];
                fileNameSpan.textContent = file.name;

                // Preview Logic
                if (previewContainer) {
                    previewContainer.innerHTML = '';
                    previewContainer.style.display = 'none';

                    const fileType = file.type;
                    const validImageTypes = ['image/gif', 'image/jpeg', 'image/png', 'image/webp'];

                    if (validImageTypes.includes(fileType)) {
                        const img = document.createElement('img');
                        img.src = URL.createObjectURL(file);
                        img.style.maxWidth = '200px';
                        img.style.maxHeight = '200px';
                        img.style.borderRadius = '4px';
                        img.style.border = '1px solid #ddd';
                        img.style.padding = '4px';
                        previewContainer.appendChild(img);
                        previewContainer.style.display = 'block';
                    } else if (fileType === 'application/pdf') {
                        const link = document.createElement('a');
                        link.href = URL.createObjectURL(file);
                        link.target = '_blank';
                        link.innerHTML = '<i class="fas fa-file-pdf"></i> View PDF';
                        link.className = 'btn-view-pdf';
                        link.style.display = 'inline-flex';
                        link.style.alignItems = 'center';
                        link.style.gap = '5px';
                        link.style.color = '#1B5E20';
                        link.style.textDecoration = 'none';
                        link.style.fontWeight = '500';
                        link.style.marginTop = '5px';
                        previewContainer.appendChild(link);
                        previewContainer.style.display = 'block';
                    }
                }
            } else {
                fileNameSpan.textContent = 'No file chosen';
                if (previewContainer) {
                    previewContainer.innerHTML = '';
                    previewContainer.style.display = 'none';
                }
            }
        });
    }

    // Dynamic Sub-Category Logic
    const categorySelect = document.getElementById("category");
    const subCategorySelect = document.getElementById("sub_category");

    if (categorySelect && subCategorySelect) {
        const subCategories = {
            "Hardware Issue": ["Printer Issue", "Monitor Issue", "Keyboard Issue", "CPU Issue"],
            "Software Issue": ["Login Error", "System Crash", "Installation Problem", "Update Issue"],
            "Network Issue": ["WiFi Not Connecting", "Slow Internet", "LAN Issue", "VPN Problem"],
            "Email Problem": ["Cannot Send", "Cannot Receive", "Spam Issue", "Login Failed"],
            "Account Access": ["Password Reset", "Account Locked", "New Account Request"]
        };

        categorySelect.addEventListener("change", function () {
            const selected = this.value;
            subCategorySelect.innerHTML = '<option value="" disabled selected hidden>Select sub-category</option>';

            if (subCategories[selected]) {
                subCategories[selected].forEach(function (item) {
                    const option = document.createElement("option");
                    option.value = item;
                    option.textContent = item;
                    subCategorySelect.appendChild(option);
                });
            }
        });
    }
});
