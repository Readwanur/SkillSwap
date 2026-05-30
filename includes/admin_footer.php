    </main>
</div>

<!-- Footer -->

<footer class="footer">
    <div class="container" style="display:flex; justify-content:center; align-items:center; flex-wrap:wrap; gap:8px;">
        <p>&copy; <?php echo date('Y'); ?> SkillSwap Admin Panel</p>
    </div>
</footer>

<script src="https://unpkg.com/lucide@latest"></script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        lucide.createIcons();
    });
    window.addEventListener("load", function() {
        clearTimeout(window.preloaderTimer);
        const preloader = document.getElementById("page-preloader");
        if (preloader) {
            if (preloader.style.opacity === "1") {
                setTimeout(() => {
                    preloader.style.opacity = "0";
                    preloader.style.visibility = "hidden";
                    setTimeout(() => { preloader.style.display = "none"; }, 500);
                }, 400);
            } else {
                preloader.style.display = "none";
            }
        }
    });
</script>
<script src="<?php echo dirname($_SERVER['SCRIPT_NAME']); ?>/../assets/js/table-pagination.js"></script>
</body>
</html>
