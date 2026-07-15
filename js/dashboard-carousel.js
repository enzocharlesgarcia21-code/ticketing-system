(function () {
    var carousels = Array.prototype.slice.call(document.querySelectorAll('.summary-carousel'));

    function initCarousel(carousel) {
        var viewport = carousel.querySelector('.carousel__viewport');
        var track = carousel.querySelector('.carousel__track');
        var prevBtn = carousel.querySelector('.carousel__nav--prev');
        var nextBtn = carousel.querySelector('.carousel__nav--next');
        var dotsContainer = carousel.querySelector('.carousel__dots');
        var announce = carousel.querySelector('.carousel__announce');
        var slides = track ? Array.prototype.slice.call(track.querySelectorAll('.carousel__slide')) : [];

        if (!viewport || !track || !prevBtn || !nextBtn || !dotsContainer || !announce || !slides.length) return;

        var index = 0;
        var scrollFrame = null;
        var resizeTimer = null;

        function isCompact() {
            var compact = viewport.getBoundingClientRect().width > 0 && viewport.getBoundingClientRect().width <= 720;
            carousel.classList.toggle('is-compact', compact);
            return compact;
        }

        function updateDots() {
            Array.prototype.forEach.call(dotsContainer.children, function (dot, dotIndex) {
                dot.setAttribute('aria-selected', dotIndex === index ? 'true' : 'false');
                dot.tabIndex = dotIndex === index ? 0 : -1;
            });
        }

        function announceActive() {
            var active = slides[index];
            if (!active) return;
            var label = active.querySelector('.summary-card__label');
            var value = active.querySelector('.summary-card__value');
            announce.textContent = (label ? label.textContent.trim() : 'Summary') + ': ' + (value ? value.textContent.trim() : '') + '. Slide ' + (index + 1) + ' of ' + slides.length + '.';
        }

        function setActiveFromScroll() {
            if (!isCompact()) return;
            var viewportRect = viewport.getBoundingClientRect();
            var viewportCenter = viewportRect.left + (viewportRect.width / 2);
            var nearestIndex = index;
            var nearestDistance = Infinity;

            slides.forEach(function (slide, slideIndex) {
                var rect = slide.getBoundingClientRect();
                var distance = Math.abs((rect.left + (rect.width / 2)) - viewportCenter);
                if (distance < nearestDistance) {
                    nearestDistance = distance;
                    nearestIndex = slideIndex;
                }
            });

            if (nearestIndex !== index) {
                index = nearestIndex;
                updateDots();
                announceActive();
            }
        }

        function scrollToIndex(nextIndex, behavior) {
            if (!isCompact()) return;
            index = Math.max(0, Math.min(nextIndex, slides.length - 1));
            var slide = slides[index];
            var target = slide.offsetLeft - ((viewport.clientWidth - slide.offsetWidth) / 2);
            viewport.scrollTo({ left: Math.max(0, target), behavior: behavior || 'smooth' });
            updateDots();
            announceActive();
        }

        function buildDots() {
            dotsContainer.innerHTML = '';
            slides.forEach(function (slide, slideIndex) {
                var label = slide.querySelector('.summary-card__label');
                var dot = document.createElement('button');
                dot.className = 'carousel__dot';
                dot.type = 'button';
                dot.setAttribute('role', 'tab');
                dot.setAttribute('aria-label', 'Show ' + (label ? label.textContent.trim() : 'summary ' + (slideIndex + 1)));
                dot.addEventListener('click', function () { scrollToIndex(slideIndex); });
                dotsContainer.appendChild(dot);
            });
        }

        function refresh() {
            track.style.transform = '';
            track.style.transition = '';
            if (isCompact()) scrollToIndex(index, 'auto');
        }

        prevBtn.addEventListener('click', function () { scrollToIndex(index - 1); });
        nextBtn.addEventListener('click', function () { scrollToIndex(index + 1); });
        carousel.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft') { event.preventDefault(); scrollToIndex(index - 1); }
            if (event.key === 'ArrowRight') { event.preventDefault(); scrollToIndex(index + 1); }
        });
        viewport.addEventListener('scroll', function () {
            if (scrollFrame) return;
            scrollFrame = window.requestAnimationFrame(function () {
                scrollFrame = null;
                setActiveFromScroll();
            });
        }, { passive: true });

        function scheduleRefresh() {
            window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(refresh, 80);
        }

        window.addEventListener('resize', scheduleRefresh);
        if (window.visualViewport) window.visualViewport.addEventListener('resize', scheduleRefresh);
        if (window.ResizeObserver) new ResizeObserver(scheduleRefresh).observe(viewport);

        buildDots();
        window.requestAnimationFrame(refresh);
        window.addEventListener('load', scheduleRefresh, { once: true });
    }

    carousels.forEach(initCarousel);
})();
