(function(){

let progress = 0;
let attempts = 0;
let maxAttempts = 120;
let timer = null;

let bar = document.getElementById('vt-progress-bar');
let statusTxt = document.getElementById('vt-status');
let detail = document.getElementById('vt-detail');

if(!bar || !statusTxt || !detail){
    return;
}

function setProgress(p){

    bar.style.width = p + '%';
    bar.setAttribute('aria-valuenow', p);
    bar.textContent = p + '%';

}

function onCompleted(){

    setProgress(100);

    bar.classList.remove('progress-bar-animated','bg-primary');
    bar.classList.add('bg-success');

    bar.textContent = '100%';

    statusTxt.textContent = 'Transcrição concluída';
    statusTxt.className = 'text-success fw-bold fs-5 mb-3';

    detail.textContent = 'Recarregando página';

    setTimeout(function(){
        window.location.reload();
    },1500);

}

function onFailed(){

    bar.classList.remove('progress-bar-animated','bg-primary');
    bar.classList.add('bg-danger');

    bar.textContent = 'Falhou';

    statusTxt.textContent = 'Falha na transcrição';
    statusTxt.className = 'text-danger fw-bold fs-5 mb-3';

    detail.textContent = 'Contate o administrador';

}

function getLabel(p){

    if(p < 20) return 'Baixando áudio do vídeo';
    if(p < 40) return 'Convertendo áudio';
    if(p < 60) return 'Enviando para IA';
    if(p < 80) return 'Gerando transcrição';
    if(p < 95) return 'Finalizando';

    return 'Quase pronto';

}

function checkStatus(){

fetch('$escaped_url',{credentials:'same-origin'})
.then(function(r){

    if(!r.ok){
        throw new Error('HTTP ' + r.status);
    }

    return r.json();

})
.then(function(data){

    attempts++;

    if(data.status === 'completed'){
        onCompleted();
        return;
    }

    if(data.status === 'failed'){
        onFailed();
        return;
    }

    progress += Math.random()*4 + 1;

    if(progress > 88){
        progress = 88;
    }

    setProgress(Math.round(progress));

    detail.textContent = getLabel(Math.round(progress));

    if(attempts < maxAttempts){

        timer = setTimeout(checkStatus,5000);

    }else{

        detail.textContent = 'Processamento demorado. Atualize a página';

    }

})
.catch(function(err){

    console.warn('VT status check error:',err);

    if(attempts < maxAttempts){
        timer = setTimeout(checkStatus,8000);
    }

});

}

timer = setTimeout(checkStatus,3000);

window.addEventListener('beforeunload',function(){

    if(timer){
        clearTimeout(timer);
    }

});

})();
