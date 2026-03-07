define([], function() {

    return {

        init: function(transcription) {

            const video = document.querySelector('.urlworkaround');

            if (!video) {
                return;
            }

            const tutor = document.createElement('div');

            tutor.style.marginTop = "20px";

            tutor.innerHTML = `
                <hr>

                <button id="vt_open">Perguntar ao Tutor</button>

                <div id="vt_box" style="display:none;margin-top:15px;">

                    <textarea id="vt_question"
                    style="width:100%;height:80px;"></textarea>

                    <br><br>

                    <button id="vt_send">Enviar</button>

                    <div id="vt_answer"
                    style="margin-top:20px;"></div>

                </div>
            `;

            video.insertAdjacentElement('afterend', tutor);

            document.getElementById('vt_open').onclick = function() {

                document.getElementById('vt_box').style.display = "block";

            };

            document.getElementById('vt_send').onclick = async function() {

                const question =
                document.getElementById('vt_question').value;

                const response = await fetch(
                    M.cfg.wwwroot + '/local/videotranscriber/chat.php',
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type':
                            'application/x-www-form-urlencoded'
                        },
                        body:
                        'question=' + encodeURIComponent(question) +
                        '&transcription=' +
                        encodeURIComponent(transcription)
                    }
                );

                const text = await response.text();

                document.getElementById('vt_answer')
                .innerHTML = text;

            };

        }

    };

});
