document.addEventListener('DOMContentLoaded', function(){
    var allBoardsDataElem = document.getElementById('allBoardsData');
    var allBoards = JSON.parse(allBoardsDataElem.textContent);
    var categorySelect = document.getElementById('category');
    var boardSelect = document.getElementById('board');
  
    // 初期表示時、GET パラメータで選択済みの掲示板があれば保持（グローバル変数として設定）
    window.initialBoard = boardSelect.getAttribute('data-initial') || '';
  
    function updateBoardSelect() {
        var selectedCategory = categorySelect.value;
        allBoards.forEach(function(b){
            if (selectedCategory === '' || b.category_name === selectedCategory) {
                var opt = document.createElement('option');
                opt.value = b.board_id;
                opt.text = b.board_name;
                boardSelect.appendChild(opt);
            }
        });
        // GET パラメータで既に選択されている掲示板があれば反映
        if(window.initialBoard) {
            boardSelect.value = window.initialBoard;
        }
    }
    categorySelect.addEventListener('change', updateBoardSelect);
    updateBoardSelect();
  });
  
  // 並び順変更時のフォーム送信関数
  function submitSortForm() {
    document.getElementById('sort_order_form').submit();
  }
  