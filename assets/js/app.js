const login = document.getElementById("loginCard");
const register = document.getElementById("registerCard");

document.getElementById("createBtn").addEventListener("click", function(e) {
    e.preventDefault();

    login.classList.remove("active");
    login.classList.add("hidden-left");

    register.classList.add("active");
});

document.getElementById("loginBtn").addEventListener("click", function(e) {
    e.preventDefault();

    register.classList.remove("active");
    register.classList.add("hidden-left");

    login.classList.add("active");
});


